<?php
/**
 * OTO Chains Tables
 *
 * wp_oto_chains  — maps a trigger product to a 1-3 step sequence
 * of offers from wp_oto_offers.
 *
 * An offer can sit in any chain position (FR: bundle can occupy
 * any OTO slot) because chains only ever store offer IDs — the
 * chain has no idea whether a given step is a single product or
 * a bundle, that's entirely encoded on the offer row itself.
 *
 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OtoChainsTable
{
  const DB_VERSION = "1.0.0";
  const OPTION_KEY = "oto_chains_db_version";

  public static function table_name()
  {
    global $wpdb;
    return $wpdb->prefix . "oto_chains";
  }

  public static function maybe_create_table()
  {
    if (get_option(self::OPTION_KEY) === self::DB_VERSION) {
      return;
    }

    global $wpdb;
    $table_name = self::table_name();
    $charset_collate = $wpdb->get_charset_collate();

    # step_2/step_3 are nullable — a chain can be 1, 2, or 3 steps long.
    $sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trigger_product_id BIGINT UNSIGNED NOT NULL,
			step_1_offer_id BIGINT UNSIGNED NOT NULL,
			step_2_offer_id BIGINT UNSIGNED DEFAULT NULL,
			step_3_offer_id BIGINT UNSIGNED DEFAULT NULL,
			priority INT NOT NULL DEFAULT 10,
			active TINYINT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY trigger_product_id (trigger_product_id),
			KEY active_priority (active, priority)
		) {$charset_collate};";

    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    dbDelta($sql); # WordPress's schema migration function
    update_option(self::OPTION_KEY, self::DB_VERSION);
  }

  public static function drop_table()
  {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS " . self::table_name()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    delete_option(self::OPTION_KEY);
  }

  /**
   * Fetches a single chain by its primary key.
   *
   * @param int $chain_id
   * @return array|null
   */
  public static function get(int $chain_id)
  {
    global $wpdb;
    $table_name = self::table_name();

    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $chain_id,
      ),
      ARRAY_A,
    );

    return $row ?: null;
  }

  /**
   * Creates a new chain.
   *
   * @param array $args {
   *     @type int $trigger_product_id
   *     @type int $step_1_offer_id
   *     @type int $step_2_offer_id  Optional.
   *     @type int $step_3_offer_id  Optional.
   *     @type int $priority         Lower runs first when multiple chains match a cart.
   * }
   * @return int Inserted chain ID, or 0 on failure.
   */
  public static function create(array $args)
  {
    global $wpdb;

    $wpdb->insert(
      self::table_name(),
      [
        "trigger_product_id" => absint($args["trigger_product_id"]),
        "step_1_offer_id" => absint($args["step_1_offer_id"]),
        "step_2_offer_id" => empty($args["step_2_offer_id"])
          ? null
          : absint($args["step_2_offer_id"]),
        "step_3_offer_id" => empty($args["step_3_offer_id"])
          ? null
          : absint($args["step_3_offer_id"]),
        "priority" => isset($args["priority"]) ? absint($args["priority"]) : 10,
        "active" => isset($args["active"]) ? absint($args["active"]) : 1,
        "created_at" => current_time("mysql"),
      ],
      ["%d", "%d", "%d", "%d", "%d", "%d", "%s"],
    );

    return (int) $wpdb->insert_id;
  }

  /**
   * Finds the best-matching active chain for a set of product IDs
   * in the order (i.e. the cart that was just purchased).
   *
   * If more than one chain matches, the lowest 'priority' value wins.
   * This is the FR requirement that the chain varies by trigger
   * product — call this once per order to decide which sequence
   * of OTO1/2/3 the customer sees.
   *
   * @param array $product_ids Product IDs from the completed order.
   * @return array|null The matching chain row, or null if none match.
   */
  public static function get_chain_for_products(array $product_ids)
  {
    global $wpdb;
    $table_name = self::table_name();

    if (empty($product_ids)) {
      return null;
    }

    $product_ids = array_map("absint", $product_ids);
    $placeholders = implode(",", array_fill(0, count($product_ids), "%d"));

    $sql = "SELECT * FROM {$table_name}
			WHERE trigger_product_id IN ({$placeholders})
			AND active = 1
			ORDER BY priority ASC
			LIMIT 1";

    $row = $wpdb->get_row($wpdb->prepare($sql, $product_ids), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return $row ?: null;
  }

  /**
   * Returns the offer ID for a given step (1-3) of a chain,
   * or null if that step doesn't exist (chains can be shorter
   * than 3 steps).
   *
   * @param array $chain Chain row from get_chain_for_products().
   * @param int   $step  1, 2, or 3.
   * @return int|null
   */
  public static function get_step_offer_id(array $chain, int $step)
  {
    $key = "step_{$step}_offer_id";
    return empty($chain[$key]) ? null : (int) $chain[$key];
  }
}

# Wire up activation and update-check hooks:

# register_activation_hook( __FILE__, array( 'OTO_Chains_Table', 'maybe_create_table' ) );
# add_action( 'plugins_loaded', array( 'OTO_Chains_Table', 'maybe_create_table' ) );
