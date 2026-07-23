<?php
/**
 * OTO Seed Script — ONE TIME USE ONLY
 *
 * INSTRUCTIONS:
 * 1. Fill in the product IDs below (STEP 1)
 * 2. Place this file in: astra-child/oto-seed.php
 * 3. Visit: https://your-site.com/wp-content/themes/astra-child/oto-seed.php
 * 4. DELETE THIS FILE immediately after running it
 *
 * DO NOT leave this file on a live server — it has no authentication
 * and will re-insert data every time it's visited.
 */

// Bootstrap WordPress
$wp_load_path = __DIR__ . "/../../../wp-load.php";
// echo __DIR__ . "/../../../../wp-load.php" . "<br>";

if (!file_exists($wp_load_path)) {
  die("Could not find wp-load.php. Check the path.");
}

require_once $wp_load_path;

// Must be logged in as admin to run this
if (!current_user_can("manage_options")) {
  die("Unauthorised. Log in as an administrator first.");
}

// Ensure tables exist before inserting
require_once get_stylesheet_directory() . "/inc/OTO_OffersTable.php";
require_once get_stylesheet_directory() . "/inc/OTO_EventsTable.php";
require_once get_stylesheet_directory() . "/inc/OTO_ChainsTable.php";

OTO_OffersTable::maybe_create_table();
OTO_EventsTable::maybe_create_table();
OTO_ChainsTable::maybe_create_table();

global $wpdb;

// ─── STEP 1: Fill in your real product IDs ───────────────────────────────────
// Go to WooCommerce → Products, hover over a product name — the ID
// shows in the URL at the bottom of the browser: post=XXXX
// Replace the zeros below with your actual product IDs.

$trigger_product_id = 42; // The product that triggers the funnel when purchased
$oto1_product_id = 46; // Product offered at OTO step 1
$oto2_product_id = 47; // Product offered at OTO step 2
$oto3_product_id = 48; // Product offered at OTO step 3

// ─── STEP 2: Configure pricing ───────────────────────────────────────────────
// price_type options:
//   'fixed'       — charge exactly price_value (e.g. 299.00)
//   'percent_off' — price_value is the % discount (e.g. 20 = 20% off)
//   'bundle_price'— flat price for a bundle of multiple products

$oto1_price_type = "percent_off"; // e.g. 20% off OTO 1
$oto1_price_value = 20.0;

$oto2_price_type = "fixed";
$oto2_price_value = 199.0;

$oto3_price_type = "fixed";
$oto3_price_value = 99.0;

// ─── Validation ──────────────────────────────────────────────────────────────

if (!$trigger_product_id || !$oto1_product_id) {
  die(
    '<p style="color:red;font-family:sans-serif;">Fill in at least $trigger_product_id and $oto1_product_id before running this script.</p>'
  );
}

$offers_table = $wpdb->prefix . "oto_offers";
$chains_table = $wpdb->prefix . "oto_chains";

$errors = [];
$results = [];

// ─── Insert OTO 1 offer ───────────────────────────────────────────────────────

$wpdb->insert(
  $offers_table,
  [
    "title" => "OTO 1 — Test Offer",
    "type" => "single",
    "product_ids" => wp_json_encode([$oto1_product_id]),
    "price_type" => $oto1_price_type,
    "price_value" => $oto1_price_value,
    "active" => 1,
    "created_at" => current_time("mysql"),
  ],
  ["%s", "%s", "%s", "%s", "%f", "%d", "%s"],
);

if (!$wpdb->insert_id) {
  $errors[] = "Failed to insert OTO 1 offer.";
} else {
  $oto1_offer_id = $wpdb->insert_id;
  $results[] = "OTO 1 offer inserted (ID: {$oto1_offer_id})";
}

// ─── Insert OTO 2 offer (if product ID provided) ─────────────────────────────

$oto2_offer_id = null;

if ($oto2_product_id) {
  $wpdb->insert(
    $offers_table,
    [
      "title" => "OTO 2 — Test Offer",
      "type" => "single",
      "product_ids" => wp_json_encode([$oto2_product_id]),
      "price_type" => $oto2_price_type,
      "price_value" => $oto2_price_value,
      "active" => 1,
      "created_at" => current_time("mysql"),
    ],
    ["%s", "%s", "%s", "%s", "%f", "%d", "%s"],
  );

  if (!$wpdb->insert_id) {
    $errors[] = "Failed to insert OTO 2 offer.";
  } else {
    $oto2_offer_id = $wpdb->insert_id;
    $results[] = "OTO 2 offer inserted (ID: {$oto2_offer_id})";
  }
}

// ─── Insert OTO 3 offer (if product ID provided) ─────────────────────────────

$oto3_offer_id = null;

if ($oto3_product_id) {
  $wpdb->insert(
    $offers_table,
    [
      "title" => "OTO 3 — Test Offer",
      "type" => "single",
      "product_ids" => wp_json_encode([$oto3_product_id]),
      "price_type" => $oto3_price_type,
      "price_value" => $oto3_price_value,
      "active" => 1,
      "created_at" => current_time("mysql"),
    ],
    ["%s", "%s", "%s", "%s", "%f", "%d", "%s"],
  );

  if (!$wpdb->insert_id) {
    $errors[] = "Failed to insert OTO 3 offer.";
  } else {
    $oto3_offer_id = $wpdb->insert_id;
    $results[] = "OTO 3 offer inserted (ID: {$oto3_offer_id})";
  }
}

// ─── Insert chain ─────────────────────────────────────────────────────────────

if (empty($errors)) {
  $wpdb->insert(
    $chains_table,
    [
      "trigger_product_id" => $trigger_product_id,
      "step_1_offer_id" => $oto1_offer_id,
      "step_2_offer_id" => $oto2_offer_id,
      "step_3_offer_id" => $oto3_offer_id,
      "priority" => 10,
      "active" => 1,
      "created_at" => current_time("mysql"),
    ],
    ["%d", "%d", "%d", "%d", "%d", "%d", "%s"],
  );

  if (!$wpdb->insert_id) {
    $errors[] = "Failed to insert chain.";
  } else {
    $chain_id = $wpdb->insert_id;
    $results[] = "Chain inserted (ID: {$chain_id}) — trigger product: {$trigger_product_id}";
  }
}

// ─── Output ──────────────────────────
?>
<!doctype html>
<html>
  <head>
    <title>OTO Seed Script</title>
    <style>
      body {
        font-family: sans-serif;
        max-width: 600px;
        margin: 40px auto;
        padding: 0 20px;
      }
      .success {
        color: green;
      }
      .error {
        color: red;
      }
      .warning {
        background: #fff3cd;
        border: 1px solid #ffc107;
        padding: 12px 16px;
        border-radius: 4px;
        margin-top: 24px;
      }
      ul {
        line-height: 2;
      }
    </style>
  </head>
  <body>
    <?php if (!empty($errors)): ?>
    <h2 class="error">Errors</h2>
    <ul>
      <?php foreach ($errors as $error): ?>
      <li class="error"><?php echo esc_html($error); ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?> <?php if (!empty($results)): ?>
    <h2 class="success">Inserted successfully</h2>
    <ul>
      <?php foreach ($results as $result): ?>
      <li class="success"><?php echo esc_html($result); ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?> <?php if (empty($errors)): ?>
    <div class="warning">
      <strong>&#9888; Delete this file now.</strong><br />
      Remove <code>astra-child/oto-seed.php</code> from your server immediately.
      Leaving it in place means anyone can visit the URL and insert duplicate
      data.
    </div>
    <?php endif; ?>
  </body>
</html>
