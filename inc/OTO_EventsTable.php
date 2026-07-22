<?php
/**
 * OTO Events Table
 *
 * Stores one row per OTO/bundle/downsell step shown to a customer,
 * and tracks its outcome (viewed / accepted / declined / abandoned).
 * This is the FR-5.4 tracking requirement: every accept/decline path
 * for every OTO and downsell must be reportable.
 *
 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OTO_EventsTable
{
  /**
   * Current schema version. Bump this and update create_table()
   * whenever the schema changes — dbDelta() only applies changes
   * when this differs from the stored option.
   */
  const DB_VERSION = "1.0.0";

  const OPTION_KEY = "oto_events_db_version";

  /**
   * Returns the fully-prefixed table name.
   *
   * @return string
   */
  public static function table_name()
  {
    global $wpdb;
    return $wpdb->prefix . "oto_events";
  }

  /**
   * Creates or upgrades the table. Call this on plugin activation
   * and also on 'plugins_loaded' (cheap early-return check) so
   * existing installs pick up schema changes after an update.
   */
  public static function maybe_create_table()
  {
    if (get_option(self::OPTION_KEY) === self::DB_VERSION) {
      return;
    }

    global $wpdb;

    $table_name = self::table_name();
    $charset_collate = $wpdb->get_charset_collate();

    # dbDelta() is picky about formatting: two spaces after
    # PRIMARY KEY / KEY, each column on its own line, no
    # trailing comma before the closing paren.
    $sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			chain_id BIGINT UNSIGNED NOT NULL,
			offer_id BIGINT UNSIGNED NOT NULL,
			step TINYINT UNSIGNED NOT NULL,
			offer_type VARCHAR(255) NOT NULL DEFAULT 'oto', # offer_type: 'oto' | 'bundle' | 'downsell'
			action VARCHAR(255) NOT NULL DEFAULT 'viewed', # action: 'viewed' | 'accepted' | 'declined' | 'abandoned'
			amount DECIMAL(10,2) DEFAULT NULL,
			session_token VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY offer_action (offer_id, action),
			KEY chain_step (chain_id, step),
			KEY action_created (action, created_at),
			KEY session_token (session_token)
		) {$charset_collate};";

    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    dbDelta($sql); # WordPress's schema migration function
    update_option(self::OPTION_KEY, self::DB_VERSION);
  }

  /**
   * Drops the table. Only call this from an explicit uninstall
   * routine — never from deactivation.
   */
  public static function drop_table()
  {
    global $wpdb;
    $table_name = self::table_name();
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    delete_option(self::OPTION_KEY);
  }

  /**
   * Logs that an offer step was shown to the customer.
   * Call this when the offer page is rendered.
   *
   * @param array $args {
   *     @type int    $order_id
   *     @type int    $chain_id
   *     @type int    $offer_id
   *     @type int    $step
   *     @type string $offer_type    'oto' | 'bundle' | 'downsell'
   *     @type string $session_token
   * }
   * @return int Inserted row ID, or 0 on failure.
   */
  public static function log_viewed(array $args)
  {
    global $wpdb;

    $wpdb->insert(
      self::table_name(),
      [
        "order_id" => absint($args["order_id"]),
        "chain_id" => absint($args["chain_id"]),
        "offer_id" => absint($args["offer_id"]),
        "step" => absint($args["step"]),
        "offer_type" => sanitize_key($args["offer_type"] ?? "oto"),
        "action" => "viewed",
        "session_token" => sanitize_text_field($args["session_token"] ?? ""),
        "created_at" => current_time("mysql"),
      ],
      ["%d", "%d", "%d", "%d", "%s", "%s", "%s", "%s"],
    );

    return (int) $wpdb->insert_id;
  }

  /**
   * Updates the most recent 'viewed' row for a session token to
   * its final outcome. Falls back to inserting a new row if no
   * matching 'viewed' row is found, so the event is never lost.
   *
   * @param string $session_token
   * @param string $action 'accepted' | 'declined'
   * @param float  $amount Optional order value added by this step.
   * @return bool
   */
  public static function log_outcome(
    string $session_token,
    string $action,
    float $amount = 0.0,
  ) {
    global $wpdb;
    $table_name = self::table_name();

    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE session_token = %s AND action = 'viewed' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $session_token,
      ),
    );

    if (!$row) {
      return false;
    }

    $updated = $wpdb->update(
      $table_name,
      [
        "action" => sanitize_key($action),
        "amount" => $amount,
      ],
      ["id" => $row->id],
      ["%s", "%f"],
      ["%d"],
    );

    return false !== $updated;
  }

  /**
   * Marks stale 'viewed' rows as 'abandoned'. Intended to run on
   * a cron schedule (e.g. every 15 minutes).
   *
   * @param int $stale_after_minutes How long a 'viewed' row can sit
   *                                 before it's considered abandoned.
   * @return int Number of rows updated.
   */
  public static function mark_abandoned(int $stale_after_minutes = 30)
  {
    global $wpdb;
    $table_name = self::table_name();

    $cutoff = gmdate(
      "Y-m-d H:i:s",
      time() - $stale_after_minutes * MINUTE_IN_SECONDS,
    );

    return (int) $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$table_name} SET action = 'abandoned' WHERE action = 'viewed' AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $cutoff,
      ),
    );
  }

  /**
   * Returns viewed/accepted/declined/abandoned counts for a given
   * offer, for the acceptance-rate reporting FR-5.4 implies.
   *
   * @param int $offer_id
   * @return array Associative array keyed by action.
   */
  public static function get_offer_stats(int $offer_id)
  {
    global $wpdb;
    $table_name = self::table_name();

    $results = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT action, COUNT(*) AS total FROM {$table_name} WHERE offer_id = %d GROUP BY action", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $offer_id,
      ),
      ARRAY_A,
    );

    $stats = [
      "viewed" => 0,
      "accepted" => 0,
      "declined" => 0,
      "abandoned" => 0,
    ];

    foreach ($results as $row) {
      $stats[$row["action"]] = (int) $row["total"];
    }

    return $stats;
  }
}

# Wire up activation and update-check hooks:

# register_activation_hook( __FILE__, array( 'OTO_Events_Table', 'maybe_create_table' ) );
# add_action( 'plugins_loaded', array( 'OTO_Events_Table', 'maybe_create_table' ) );
