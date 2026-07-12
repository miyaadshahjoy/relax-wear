<?php

function dx_get_product_bump_config()
{
  return [
    47 => [
      "offer_product_id" => 48,
      "offer_price" => 99.0,
      "headline" =>
        "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor",
      "description" =>
        "From its medieval origins to the digital era, learn everything there is to know about the ubiquitous lorem ipsum passage.",
    ],
  ];
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
  $bump_matrix = dx_get_product_bump_config();
  $active_offer = null;
  $core_product_id = 0;

  # Collect all core product IDs present in the cart
  $cart_product_ids = [];
  foreach (WC()->cart->get_cart() as $cart_item) {
    $cart_product_ids[] = $cart_item["product_id"];
  }

  # Evaluate matching triggers
  foreach ($cart_product_ids as $pid) {
    if (array_key_exists($pid, $bump_matrix)) {
      # Potential offer
      $potential_offer = $bump_matrix[$pid];

      # TODO: Only add products if it is not already added to the cart manually
      if (!in_array($potential_offer["offer_product_id"], $cart_product_ids)) {
        $active_offer = $potential_offer;
        $core_product_id = $pid;
        break;
      }
    }
  }

  $session_active = WC()->session->get("apply_product_bump");
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
  if (!$active_offer) {
    return;
  }

  $offer_product = wc_get_product($active_offer["offer_product_id"]);

  if (!$offer_product) {
    return;
  }
  $checked = WC()->session->get("apply_product_bump")
    ? 'checked="checked"'
    : "";

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
      border: 2px dashed #0071e3;
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
          id="dx_product_bump_checkbox"
          value="1"
          <?php echo $checked; ?>
          style="transform: scale(1.3); cursor: pointer"
        />
      </div>

      <div style="flex: 1">
        <span
          style="
            background: #0071e3;
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
            gap: 15px;
            background: #fff;
            padding: 12px;
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
              width: 50px;
              height: 50px;
              object-fit: cover;
              border-radius: 4px;
              border: 1px solid #eee;
            "
          />
          <div style="flex: 1">
            <div
              style="
                font-weight: 600;
                color: #1d1d1f;
                font-size: 14px;
                margin-bottom: 2px;
              "
            >
              <?php echo esc_html($offer_product->get_name()); ?>
            </div>
            <div style="font-size: 13px">
              <span
                style="
                  text-decoration: line-through;
                  color: #86868b;
                  margin-right: 6px;
                "
                ><?php echo $reg_price; ?></span
              >
              <span style="color: #bf4800; font-weight: 700; margin-right: 10px"
                ><?php echo $offer_price; ?></span
              >
              <?php if ($saved_amount > 0): ?>
                <span style="color: #1d1d1f; font-weight: 500; background: #e3f2fd; padding: 2px 6px; border-radius: 3px; font-size: 11px;">Save <?php echo $saved_amount; ?></span>
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
        if (e.target && e.target.id === "dx_product_bump_checkbox") {
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
  if (is_admin() && !defined("DOING_AJAX")) {
    return;
  }

  $bump_matrix = dx_get_product_bump_config();
  $cart_contents = $cart->get_cart_contents();

  # Find any trigger core items inside the current basket
  $present_core_ids = [];
  foreach ($cart_contents as $item) {
    if (array_key_exists($item["product_id"], $bump_matrix)) {
      $present_core_ids[] = $item["product_id"];
    }
  }

  $target_offer_id = 0;
  $target_offer_price = 0.0;

  if (!empty($present_core_ids)) {
    $active_core_id = $present_core_ids[0];
    $target_offer_id = $bump_matrix[$active_core_id]["offer_product_id"];
    $target_offer_price = $bump_matrix[$active_core_id]["offer_price"];
  }

  $session_active = WC()->session->get("apply_product_bump");

  # Inject product if box is ticked AND core product exists

  if ($session_active && $target_offer_id !== 0) {
    # Add a unique stamp to the product added from the order bump
    $bump_cart_id = $cart->generate_cart_id(
      $target_offer_id,
      0,
      [],
      ["is_order_bump" => true],
    );
    $cart_item_key = $cart->find_product_in_cart($bump_cart_id);

    if (!$cart_item_key) {
      $cart_item_key = $cart->add_to_cart(
        $target_offer_id,
        1,
        0,
        [],
        ["is_order_bump" => true],
      );
      $cart_contents = $cart->get_cart_contents();
    } else {
      # Disallow the users to increase offer products quantity (force quantity to 1)

      if (
        isset($cart_contents[$cart_item_key]) &&
        $cart_contents[$cart_item_key]["quantity"] !== 1
      ) {
        $cart->set_quantity($cart_item_key, 1, false);
      }
    }

    # Apply distinct pricing override to stamp items
    if (isset($cart_contents[$cart_item_key])) {
      $cart_contents[$cart_item_key]["data"]->set_price($target_offer_price);
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
  }
}
