<?php

# Get the order bump configuration
function dx_get_bundle_order_bump_config()
{
  # TODO: Set priority for each category
  return [
    "hoodies" => [
      42 => 12.6, # # Beanie
      45 => 63.0, # Sunglasses
      48 => 17.5, # Long Sleeve T-Shirt
    ],
    "accessories" => [
      43 => 38.5, # Belt
      45 => 63.0, # Sunglasses
    ],
  ];
}

/*
  # Custom Multi-product Order Bump for WooCommerce using AJAX Fetch API

  |> Triggers when a "X" product is in the card and offers "a", "b", and "c" products as a bundle.

*/

# 1) Render the HTML Checkbox on the Checkout Page

add_action(
  "woocommerce_review_order_before_submit",
  "dx_custom_multi_product_bundle_offer_order_bump_fetch",
);

function dx_custom_multi_product_bundle_offer_order_bump_fetch()
{
  $bump_matrix = dx_get_bundle_order_bump_config();
  $active_bundle_products = [];
  $matched_category_slug = "";

  # Loop through cart items to find a matching category from our config matrix
  foreach (WC()->cart->get_cart() as $cart_item) {
    foreach ($bump_matrix as $category_slug => $bundle_data) {
      if (has_term($category_slug, "product_cat", $cart_item["product_id"])) {
        $active_bundle_products = $bundle_data;
        $matched_category_slug = $category_slug;
        break 2; # Break out of both loops immediately once a match is found
      }
    }
  }

  # Check if there is a target category
  if (empty($active_bundle_products)) {
    return;
  }

  $checked = WC()->session->get("apply_bundle_bump") ? 'checked="checked"' : "";

  # Calculations for total bundle price
  $total_bundle_price = 0;
  $total_bundle_discounted_price = 0;
  $currency_symbol = get_woocommerce_currency_symbol();

  foreach ($active_bundle_products as $product_id => $discounted_price) {
    $product = wc_get_product($product_id);
    if ($product) {
      $total_bundle_price += (float) $product->get_regular_price();
      $total_bundle_discounted_price += (float) $discounted_price;
    }
  }

  $total_saved = $total_bundle_price - $total_bundle_discounted_price;
  ?>
    <!-- HTML Markup: Custom Multi-product Order Bump -->
    <div
      class="custom-order-bump-wrapper"
      style="
        border: 2px dashed #ff6600;
        padding: 20px;
        margin: 20px 0;
        background-color: #fff9f5;
        border-radius: 5px;
      "
    >
      <!-- Header Logic -->
      <div style="margin-bottom: 15px">
        <label style="display: flex; align-items: baseline; cursor: pointer">
          <input
            type="checkbox"
            id="add_custom_bundle_bump"
            name="add_custom_bundle_bump"
            value="1"
            <?php echo $checked; ?>
            style="margin-top: 0; margin-right: 10px; transform: scale(1.2)"
          />
          <strong style="color: #ff6600; font-size: 16px; line-height: 1;"
            >Frequently bought together.</strong
          >
        </label>
        <p style="font-size: 14px; color: #555; margin: 5px 0 0 28px">
          Tick the box to add this bundle to your order at a awesome discount price!
        </p>
      </div>

      <!-- Dynamic Bundle Items Row Layout -->
      <div
        class="bundle-items-preview-row"
        style="
          display: flex;
          flex-wrap: wrap;
          gap: 20px;
          margin-left: 28px;
          background: #fff;
          padding: 16px;
          margin-bottom: 16px;
          border-radius: 4px;
          border: 1px solid #f0e2d5;
        "
      >

        <?php foreach (
          $active_bundle_products
          as $product_id => $discounted_price
        ):

          $product = wc_get_product($product_id);
          if (!$product) {
            continue;
          }

          $image_url = wp_get_attachment_image_url(
            $product->get_image_id(),
            "thumbnail",
          );
          $product_name = $product->get_name();
          $original_price = $product->get_regular_price();
          $currency_symbol = get_woocommerce_currency_symbol();
          ?>

          <div
            class="bundle-preview-item"
            style="
              display: flex;
              align-items: center;
              gap: 12px;
              min-width: 220px;
              flex: 1;
            "
          >
            <div class="bundle-img-wrapper">
              <img
                src="<?php echo $image_url; ?>"
                alt="<?php echo $product_name; ?>"
                style="
                  width: 50px;
                  height: 50px;
                  object-fit: cover;
                  border-radius: 4px;
                  border: 1px solid #ddd;
                "
              />
            </div>
            <div
              class="bundle-item-meta"
              style="font-size: 13px; line-height: 1.3"
            >
              <div style="font-weight: 600; color: #333; margin-bottom: 3px">
                  <?php echo esc_html($product_name); ?>
              </div>
              <div>
                <span
                  style="
                    text-decoration: line-through;
                    color: #999;
                    margin-right: 6px;
                  "
                >
                  <?php echo $currency_symbol .
                    wc_format_decimal($original_price, 2); ?>
      
                </span>
                <span style="color: #d94000; font-weight: bold"> 
                  <?php echo $currency_symbol .
                    wc_format_decimal($discounted_price, 2); ?></span>
              </div>
            </div>
          </div>
        <?php
        endforeach; ?>
      </div>
      <!-- TOTALS SUMMARY OVERVIEW BLOCK -->
      <div
        class="bundle-totals-summary"
        style="
          display: flex;
          flex-direction: column;
          gap: 12px;

          flex: 1.5;
          min-width: 220px;
          padding-left: 20px;
          border-left: 2px solid #f5e6da;
          font-size: 14px;
          color: #333;
          line-height: 1.4;
        "
      >
        <div>
          Total Reg. Price:
          <span style="text-decoration: line-through; color: #777">
            <?php echo $currency_symbol .
              wc_format_decimal($total_bundle_price, 2); ?>
          </span>
        </div>
        <div style="font-size: 16px;">
          <strong>Bundle Price: </strong>
          <span style="color: #ff6600; font-weight: 700">
            <?php echo $currency_symbol .
              wc_format_decimal($total_bundle_discounted_price, 2); ?>
          </span>
        </div>
        <div
          style="
            color: #2e7d32;
            font-weight: 600;
            font-size: 13px;
            background: #edf7ed;
            display: inline-block;
          "
        >
          You Save: <?php echo $currency_symbol .
            wc_format_decimal($total_saved, 2); ?>!
        </div>
      </div>

    </div>

    <script type="text/javascript">
      document.addEventListener("DOMContentLoaded", function () {
        const bumpCheckbox = document.getElementById("add_custom_bundle_bump");

        if (!bumpCheckbox) return;

        document.body.addEventListener("change", function (e) {
          const isChecked = e.target.checked ? 1 : 0;

          const formData = new FormData();
          formData.append("action", "toggle_bundle_bump");
          formData.append("checked", isChecked);

          fetch(wc_checkout_params.ajax_url, {
            method: "POST",
            body: formData,
          })
            .then((response) => {
              if (response.ok) {
                document.body.dispatchEvent(new CustomEvent("update_checkout"));
              }
            })
            .catch((error) =>
              console.error("Error handling order bump payload:", error),
            );
        });
      });
    </script>
  <?php
}

# 2) Handle the AJAX toggle event & save state to session

add_action(
  "wp_ajax_toggle_bundle_bump",
  "dx_handle_bundle_offer_order_bump_ajax_fetch",
);
add_action(
  "wp_ajax_nopriv_toggle_bundle_bump",
  "dx_handle_bundle_offer_order_bump_ajax_fetch",
);

function dx_handle_bundle_offer_order_bump_ajax_fetch()
{
  if (isset($_POST["checked"])) {
    $state = intval($_POST["checked"]) === 1 ? true : false;
    WC()->session->set("apply_bundle_bump", $state);
  }
  wp_die();
}

# 3) Dynamically inject | remove the products and override prices based on session state

add_action(
  "woocommerce_before_calculate_totals",
  "dx_apply_custom_bundle_prices_and_items_fetch",
  10,
  1,
);

function dx_apply_custom_bundle_prices_and_items_fetch($cart)
{
  # Ensure this function is only run on the checkout page
  if (is_admin() && !defined("DOING_AJAX")) {
    return;
  }

  # Define bundled items array configuration (Product ID => Discounted Price)

  # Get the order bump configuration
  $bump_matrix = dx_get_bundle_order_bump_config();
  $active_bundle_products = [];
  $all_possible_bundle_ids = [];

  # 3.A) Collect all possible bundle product IDs across all rules for clean up later
  foreach ($bump_matrix as $cat => $products) {
    foreach ($products as $pid => $price) {
      $all_possible_bundle_ids[$pid] = $price;
    }
  }

  # 3.B) Determine which specific bundle rule applies right now based on what's in the cart
  foreach ($cart->get_cart() as $cart_item) {
    foreach ($bump_matrix as $category_slug => $bundle_data) {
      if (has_term($category_slug, "product_cat", $cart_item["product_id"])) {
        $active_bundle_products = $bundle_data;
        break 2;
      }
    }
  }

  $should_have_bundle = WC()->session->get("apply_bundle_bump");

  # C) Handle Addition & Price Locking

  if ($should_have_bundle && !empty($active_bundle_products)) {
    $cart_contents = $cart->get_cart_contents();

    foreach ($active_bundle_products as $product_id => $discounted_price) {
      $cart_item_key = $cart->find_product_in_cart(
        $cart->generate_cart_id($product_id, 0, [], ["is_bundle_bump" => true]),
      );

      if (!$cart_item_key) {
        $cart_item_key = $cart->add_to_cart(
          $product_id,
          1,
          0,
          [],
          ["is_bundle_bump" => true],
        );
      } else {
        if (
          isset($cart_contents[$cart_item_key]) &&
          $cart_contents[$cart_item_key]["quantity"] !== 1
        ) {
          $cart->set_quantity($cart_item_key, 1, false);
        }
      }

      if (isset($cart_contents[$cart_item_key])) {
        $cart_contents[$cart_item_key]["data"]->set_price($discounted_price);
      }
    }
  }
  # D) Handle Complete Removal or Switch-overs
  else {
    # Loop and remove ANY stamped bundle item currently in the cart
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
      if (
        isset($cart_item["is_bundle_bump"]) &&
        $cart_item["is_bundle_bump"] === true
      ) {
        $cart->remove_cart_item($cart_item_key);
      }
    }
  }
}
