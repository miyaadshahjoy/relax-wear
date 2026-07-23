<?php
// /astra-child/inc/OTO_OffersTable.php
// Refactoring: done
/**
 * OTO Offers Tables
 *
 * wp_oto_offers  — one row per offer (single product or bundle),
 *                  optionally pointing at a downsell offer.
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

class OTO_OffersTable
{
  const DB_VERSION = "1.0.0";
  const OPTION_KEY = "oto_offers_db_version";

  public static function table_name()
  {
    global $wpdb;
    return $wpdb->prefix . "oto_offers";
  }

  public static function maybe_create_table()
  {
    error_log("Offer table created");
    if (get_option(self::OPTION_KEY) === self::DB_VERSION) {
      return;
    }

    global $wpdb;
    $table_name = self::table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,                      
			type VARCHAR(255) NOT NULL DEFAULT 'single',     
			product_ids TEXT NOT NULL,                       
			price_type VARCHAR(255) NOT NULL DEFAULT 'fixed',
			price_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			downsell_offer_id BIGINT UNSIGNED DEFAULT NULL,  # self-referencing (points to another row in this same table).
			template_id VARCHAR(255) DEFAULT NULL,
			active TINYINT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY active (active),
			KEY downsell_offer_id (downsell_offer_id)
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
   * Creates a new offer.
   *
   * @param array $args {
   *     @type string $title
   *     @type string $type              'single' | 'bundle'
   *     @type array  $product_ids       One product ID for 'single', 2+ for 'bundle'.
   *     @type string $price_type        'fixed' | 'percent_off' | 'bundle_price'
   *     @type float  $price_value
   *     @type int    $downsell_offer_id Optional, nullable.
   *     @type string $template_id       Optional.
   * }
   * @return int Inserted offer ID, or 0 on failure.
   */
  public static function create(array $args)
  {
    global $wpdb;

    $wpdb->insert(
      self::table_name(),
      [
        "title" => sanitize_text_field($args["title"]),
        "type" => sanitize_key($args["type"] ?? "single"),
        "product_ids" => wp_json_encode(
          array_map("absint", $args["product_ids"]),
        ),
        "price_type" => sanitize_key($args["price_type"] ?? "fixed"),
        "price_value" => (float) ($args["price_value"] ?? 0),
        "downsell_offer_id" => empty($args["downsell_offer_id"])
          ? null
          : absint($args["downsell_offer_id"]),
        "template_id" => isset($args["template_id"])
          ? sanitize_key($args["template_id"])
          : null,
        "active" => isset($args["active"]) ? absint($args["active"]) : 1,
        "created_at" => current_time("mysql"),
      ],
      ["%s", "%s", "%s", "%s", "%f", "%d", "%s", "%d", "%s"],
    );

    return (int) $wpdb->insert_id;
  }

  /**
   * Fetches a single offer, with product_ids decoded back to an array.
   *
   * @param int $offer_id
   * @return array|null
   */
  public static function get(int $offer_id)
  {
    global $wpdb;
    $table_name = self::table_name();

    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $offer_id,
      ),
      ARRAY_A,
    );

    if (!$row) {
      return null;
    }

    $row["product_ids"] = json_decode($row["product_ids"], true);

    return $row;
  }
}
