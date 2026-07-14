<?php

function dx_get_product_bump_config()
{
  return [
    39 => [
      "offer_product_id" => 45, // Sunglasses
      "offer_price" => 63.0,
      "headline" => "Shade your eyes, elevate your style.",
      "description" =>
        "Classic design meets modern UV protection. Whether you're hitting the beach or driving into the sunset, do it with effortless confidence.",
      "priority" => 1,
      "core_product_id" => 40,
    ],
    46 => [
      "offer_product_id" => 48, // Long sleeve T-Shirt
      "offer_price" => 17.5,
      "headline" => "The ultimate everyday layer.",
      "description" =>
        "Crafted from ultra-soft, breathable cotton, this long-sleeve tee offers the perfect balance of casual comfort and versatile style for any season.",
      "priority" => 2,
      "core_product_id" => 39,
    ],
    47 => [
      "offer_product_id" => 42, // Beanie
      "offer_price" => 14.0,
      "headline" => "Stay warm. Look sharp.",
      "description" =>
        "Don't let the chill ruin your outfit. This snug, knit beanie delivers essential warmth and a clean look that complements any winter wardrobe.",
      "priority" => 3,
      "core_product_id" => 47,
    ],
  ];
}
/*
add_action("wp_footer", function () {
  echo "<pre>";

  foreach (WC()->cart->get_cart() as $cart_item) {
    print_r($cart_item["product_id"]);
    print_r($cart_item);
  }
});
*/

function getActiveOffer(array $cart_items)
{
  $bump_matrix = dx_get_product_bump_config();
  $active_offer = null;

  # Collect all CORE product IDs present in the cart
  $cart_product_ids = [];

  foreach ($cart_items as $cart_item) {
    if (
      isset($cart_item["is_order_bump"]) &&
      $cart_item["is_order_bump"] === true
    ) {
      continue;
    }

    array_push($cart_product_ids, $cart_item["product_id"]);
  }

  $potential_offers = [];

  # Evaluate matching TRIGGERS in the cart
  foreach ($cart_product_ids as $pid) {
    if (array_key_exists($pid, $bump_matrix)) {
      # POTENTIAL OFFERS
      array_push($potential_offers, $bump_matrix[$pid]);
    }
  }

  # If potential offer products are already added in the cart manually then filter them out from the potential offers list

  $potential_offers = array_filter(
    $potential_offers,
    fn($offer) => !in_array($offer["offer_product_id"], $cart_product_ids),
  );

  # If there are multiple OFFER PRODUCTS stored inside POTENTIAL OFFERS list then SORT them by PRIORITY ( 0 -> has the highest priority )
  if (empty($potential_offers)) {
    return;
  }
  uasort($potential_offers, fn($a, $b) => $a["priority"] - $b["priority"]);

  $active_offer = array_values($potential_offers)[0];

  return $active_offer;
}

/*
  # Custom Order bump for WooCommerce using AJAX Fetch API
  # Add order-bump on checkout page for multiple target products
*/

# 1) Render the HTML Checkbox on the Checkout Page
add_action(
  "woocommerce_review_order_before_submit",
  "dx_product_order_bump_render_ui",
);

function dx_product_order_bump_render_ui()
{
  $core_product_id = 0;

  $checked = WC()->session->get("apply_product_bump")
    ? 'checked="checked"'
    : "";

  /*
  if (!$active_offer && !$session_active) {
    return;
  }

  if (!$active_offer && $session_active) {
    foreach ($cart_product_ids as $pid) {
      if (array_key_exists($pid, $bump_matrix)) {
        $active_offer = $bump_matrix[$pid];
        break;
      }
    }
  }
*/

  $active_offer = getActiveOffer(WC()->cart->get_cart());

  $session_active = WC()->session->get("apply_product_bump");

  if (!$active_offer && !$session_active) {
    return;
  }

  $offer_product = wc_get_product($active_offer["offer_product_id"]);

  if (!$offer_product) {
    return;
  }
  $core_product_id = $active_offer["core_product_id"];

  $session_active = WC()->session->get("apply_product_bump");

  # Details of the OFFER PRODUCT
  $currency = get_woocommerce_currency_symbol();
  $reg_price = (float) $offer_product->get_regular_price();
  $offer_price = (float) $active_offer["offer_price"];
  $saved_amount = $reg_price - $offer_price;

  $offer_price = $currency . number_format($offer_price, 2);
  $reg_price = $currency . number_format($reg_price, 2);
  $saved_amount = $currency . number_format($saved_amount, 2);
  ?>
  <div
    class="dx-standalone-bump-box"
    style="
      border: 2px dashed #046bd2;
      padding: 20px;
      margin: 25px 0;
      background-color: #f5fafd;
      border-radius: 8px;
      font-family: -apple-system, BlinkMacSystemFont, sans-serif;
    "
  >
    <div style="display: flex; gap: 15px; align-items: flex-start">
      <div style="margin-top: 4px">
        <input
          type="checkbox"
          id="dx_product_order_bump_checkbox"
          value="1"
          <?php echo $checked; ?>      
          style="transform: scale(1.5); cursor: pointer"
        />
      </div>

      <div style="flex: 1">
        <span
          style="
            background: #046bd2;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 3px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
            margin-bottom: 8px;
          "
          >Special Upgrade</span
        >
        <h4
          style="
            margin: 0 0 6px 0;
            color: #1d1d1f;
            font-size: 16px;
            font-weight: 600;
          "
        >
          <?php echo esc_html($active_offer["headline"]); ?>     
        </h4>
        <p
          style="
            margin: 0 0 15px 0;
            color: #515154;
            font-size: 13px;
            line-height: 1.4;
          "
        >
          <?php echo esc_html($active_offer["description"]); ?>
        </p>

        <!-- Inner Product Info Row -->
        <div
          style="
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #e3e3e8;
          "
        >
          <img
            src="<?php echo esc_url(
              wp_get_attachment_image_url(
                $offer_product->get_image_id(),
                "thumbnail",
              ),
            ); ?>"
            alt=""
            style="
              width: 60px;
              height: 60px;
              object-fit: cover;
              border-radius: 4px;
              border: 1px solid #eee;
            "
          />
          
          <div style="flex: 1">
            <div
              style="
                font-weight: 600;
                color: #343a40;
                font-size: 14px;
                margin-bottom: 2px;
              "
            >
              <?php echo esc_html($offer_product->get_name()); ?>
              Product name
            </div>
            <div style="font-size: 13px">
              <span
                style="
                  text-decoration: line-through;
                  color: #868e96;
                  margin-right: 6px;
                "
                ><?php echo $reg_price; ?></span
              >
              <span style="color: #d9480f; font-weight: 700; margin-right: 10px; font-size: 18px;"
                ><?php echo $offer_price; ?></span
              >
              <?php if ($saved_amount > 0): ?>
                <span style="color: #343a40; font-weight: 500; background: #e3f2fd; padding: 2px 6px; border-radius: 3px; font-size: 11px;">Save <?php echo $saved_amount; ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>


  <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function () {
      document.body.addEventListener("change", function (e) {
        if (e.target && e.target.id === "dx_product_order_bump_checkbox") {
          const formData = new FormData();
          formData.append("action", "toggle_product_bump");
          formData.append("checked", e.target.checked ? 1 : 0);

          fetch(wc_checkout_params.ajax_url, {
            method: "POST",
            body: formData,
          }).then((res) => {
            if (res.ok)
              document.body.dispatchEvent(new CustomEvent("update_checkout"));
          });
        }
      });
    });
  </script>

<?php
}

# 2) Handle the AJAX Request
add_action("wp_ajax_toggle_product_bump", "dx_handle_order_bump_ajax");
add_action("wp_ajax_nopriv_toggle_product_bump", "dx_handle_order_bump_ajax");
function dx_handle_order_bump_ajax()
{
  // check_ajax_referer("standalone_bump_nonce", "security");
  if (isset($_POST["checked"])) {
    WC()->session->set("apply_product_bump", intval($_POST["checked"]) === 1);
  }
  wp_die();
}

# 3) Apply the Order Bump
add_action(
  "woocommerce_before_calculate_totals",
  "dx_apply_product_order_bump_rules",
);

function dx_apply_product_order_bump_rules($cart)
{
  # Only run on Checkout Page
  if (is_admin() && !defined("DOING_AJAX")) {
    return;
  }
  $offer_product_id = 0;
  $core_product_id = 0;

  $active_offer = getActiveOffer($cart->get_cart_contents());
  if (empty($active_offer)) {
    return;
  }
  $offer_product_id = $active_offer["offer_product_id"];
  $offer_product_price = $active_offer["offer_price"];
  $core_product_id = $active_offer["core_product_id"];

  if (!WC()->session) {
    return;
  }

  $session_active = WC()->session->get("apply_product_bump");

  # Inject product if box is ticked AND core product exists

  if ($session_active && $offer_product_id !== 0 && $core_product_id !== 0) {
    # Add a UNIQUE STAMP to the OFFER PRODUCT (added from the order bump)
    $order_bump_cart_id = $cart->generate_cart_id(
      $offer_product_id, # Product ID
      0, # variation ID
      [], # Variation
      ["is_order_bump" => true], # Cart Item Data
    );

    $cart_item_key = $cart->find_product_in_cart($order_bump_cart_id); # Returns CART ITEM KEY
    if (!$cart_item_key) {
      $cart_item_key = $cart->add_to_cart(
        $offer_product_id, # Product ID
        1, # Quantity
        0, # Variation ID
        [], # Variation
        ["is_order_bump" => true], # Cart Item Data
      ); # Also returns CART ITEM KEY
    } else {
      # Disallow the users to increase offer products quantity (force quantity to 1)

      if (
        isset($cart->get_cart_contents()[$cart_item_key]) &&
        $cart->get_cart_contents()[$cart_item_key]["quantity"] !== 1
      ) {
        $cart->set_quantity(
          $cart_item_key, # Cart Item Key
          1, # Quantity
          false, # Refresh totals
        );
      }
    }

    # Apply distinct pricing override to stamp items
    if (isset($cart->cart_contents[$cart_item_key])) {
      $cart->cart_contents[$cart_item_key]["data"]->set_price(
        $offer_product_price,
      );
    }
  } else {
    # Remove offer product when core product is removed (or box is unchecked)
    foreach ($cart->get_cart() as $key => $cart_item) {
      if (
        isset($cart_item["is_order_bump"]) &&
        $cart_item["is_order_bump"] === true
      ) {
        $cart->remove_cart_item($key);
      }
    }
    WC()->session->set("apply_product_bump", false);
  }
}

# 4)
add_action(
  "woocommerce_cart_item_removed",
  "dx_detect_bump_removal_from_cart",
  10,
  2,
);
function dx_detect_bump_removal_from_cart(string $cart_item_key, object $cart)
{
  if (!WC()->session) {
    return;
  }

  $removed_item = isset($cart->removed_cart_contents[$cart_item_key])
    ? $cart->removed_cart_contents[$cart_item_key]
    : null;

  if (
    $removed_item &&
    isset($removed_item["is_order_bump"]) &&
    $removed_item["is_order_bump"] === true
  ) {
    WC()->session->set("apply_product_bump", false);
  }
}
