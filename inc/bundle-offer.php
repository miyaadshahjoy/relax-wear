<?php

# Get the order bump configuration
function dx_get_bundle_order_bump_config()
{
  # FIXME: Set priority for each CATEGORY
  return [
    "hoodies" => [
      "products" => [
        42 => 12.6, # # Beanie
        45 => 63.0, # Sunglasses
        48 => 17.5, # Long Sleeve T-Shirt
      ],
      "priority" => 1,
    ],
    "accessories" => [
      "products" => [
        43 => 45.5, # Belt -> Regular Price: 65.00
        45 => 63.0, # Sunglasses -> regular price: 90.00
      ],
      "priority" => 2,
    ],
  ];
}

function dx_get_active_bundle_offer_products(array $cart)
{
  $bump_matrix = dx_get_bundle_order_bump_config();
  $active_bundle_products = [];
  $matched_category_slug = "";
  # FIND TARGET CATEGORY IN THE CART ITEMS
  # Gather all unique CATEGORY SLUG present in the CART
  $cart_category_slugs = [];

  foreach ($cart as $cart_item) {
    $terms = get_the_terms($cart_item["product_id"], "product_cat");

    # A product can have multiple categories
    if (!is_wp_error($terms) && !empty($terms)) {
      foreach ($terms as $term) {
        $cart_category_slugs[$term->slug] = true;
      }
    }
  }

  # Find the best match from the unique cart category slugs with the bump matrix based on priority
  # We sort the bump matrix based on priority first (0 -> highest priority)
  uasort($bump_matrix, function ($a, $b) {
    return $a["priority"] <=> $b["priority"];
  });

  foreach ($bump_matrix as $category_slug => $bundle_data) {
    if (isset($cart_category_slugs[$category_slug])) {
      $active_bundle_products = $bundle_data["products"];
      $matched_category_slug = $category_slug;
      break; # Found the highest priority match, exit
    }
  }

  return [
    "active_bundle_products" => $active_bundle_products,
    "matched_category_slug" => $matched_category_slug,
  ];
}

# Check if the cart contains any product from the active offer list naturally
function dx_cart_has_overlapping_bundle_products(array $active_bundle_products)
{
  if (empty($active_bundle_products)) {
    return false;
  }

  foreach (WC()->cart->get_cart() as $cart_item) {
    # Only check "natural" additions (ignore items added by our order bump)
    if (!isset($cart_item["is_bundle_bump"])) {
      if (array_key_exists($cart_item["product_id"], $active_bundle_products)) {
        return true; # match found, exit
      }
    }
  }

  return false; # no match
}

/*
  # Custom Multi-product Bundle-Offer Order Bump for WooCommerce using AJAX Fetch API

  |> Triggers when "X" product is in the cart, a bundle offer of "a", "b", and "c" products is shown on the checkout page.

*/

# 1) Render the HTML Checkbox on the Checkout Page

add_action(
  "woocommerce_review_order_before_submit",
  "dx_custom_multi_product_bundle_offer_order_bump_render_ui",
);

function dx_custom_multi_product_bundle_offer_order_bump_render_ui()
{
  $bump_matrix = dx_get_bundle_order_bump_config();
  $active_bundle_products = [];
  $matched_category_slug = "";

  # 1.A) FIND TARGET CATEGORY IN THE CART ITEMS
  # Gather all unique CATEGORY SLUG present in the CART
  $cart_category_slugs = [];

  foreach (WC()->cart->get_cart() as $cart_item) {
    $terms = get_the_terms($cart_item["product_id"], "product_cat");

    # A product can have multiple categories
    if (!is_wp_error($terms) && !empty($terms)) {
      foreach ($terms as $term) {
        $cart_category_slugs[$term->slug] = true;
      }
    }
  }

  # Find the best match from the unique cart category slugs with the bump matrix based on priority
  # We sort the bump matrix based on priority first (0 -> highest priority)
  uasort($bump_matrix, function ($a, $b) {
    return $a["priority"] <=> $b["priority"];
  });

  foreach ($bump_matrix as $category_slug => $bundle_data) {
    if (isset($cart_category_slugs[$category_slug])) {
      $active_bundle_products = $bundle_data["products"];
      $matched_category_slug = $category_slug;
      break; # Found the highest priority match, exit
    }
  }

  # 1.B) Check if there is a target category in the CART
  if (empty($active_bundle_products)) {
    return;
  }

  # Do not show the UI if cart has overlapping bundle products
  if (dx_cart_has_overlapping_bundle_products($active_bundle_products)) {
    return;
  }

  # 1.C) Change the checkbox UI elements' state based on session state:
  $checked = WC()->session->get("apply_bundle_bump") ? 'checked="checked"' : "";

  # 1.D) Calculations for Total Bundle Price
  $total_bundle_price = 0;
  $total_bundle_discounted_price = 0;
  $currency_symbol = get_woocommerce_currency_symbol();

  foreach ($active_bundle_products as $product_id => $offer_price) {
    $product = wc_get_product($product_id);
    if ($product) {
      $total_bundle_price += (float) $product->get_regular_price();
      $total_bundle_discounted_price += (float) $offer_price;
    }
  }

  $total_saved = $total_bundle_price - $total_bundle_discounted_price;
  ?>
    <!-- HTML Markup: Custom Multi-product Order Bump -->
    <div
      class="custom-order-bump-wrapper"
      style="
        border: 2px dashed #046bd2;
        padding: 20px;
        margin: 24px 0;
        background-color: #f5fafd;
        border-radius: 8px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;

      "
    >
      <div style="display: flex; gap: 16px; align-items: flex-start">
      
        <div style="margin-top: 4px">
          
          <input
            type="checkbox"
            id="add_custom_bundle_bump"
            name="add_custom_bundle_bump"
            value="1"
            <?php echo $checked; ?>
            style="margin-top: 0; cursor: pointer; transform: scale(1.5);"
          />
            
        </div>
        <div style="flex: 1">
          <!--  -->
          <strong style="color: #046bd2; font-size: 18px; line-height: 1; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px"
              >Frequently bought together.</strong
            >
          <p style="font-size: 14px; color: #343a40; margin-bottom: 24px">
            Tick the box to add this bundle to your order at an awesome discount price!
          </p>
          <!-- Dynamic Bundle Items Row Layout -->
          <div
            class="bundle-items-preview-row"
            style="
              display: flex;
              flex-wrap: wrap;
              gap: 20px;
              background: #fff;
              padding: 8px;
              margin-bottom: 16px;
              border-radius: 4px;
              border: 1px solid #f0e2d5;
            "
          >

            <?php foreach (
              $active_bundle_products
              as $product_id => $offer_price
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
                      width: 60px;
                      height: 60px;
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
                  <div style="font-weight: 600; color: #343a40; margin-bottom: 3px">
                      <?php echo esc_html($product_name); ?>
                  </div>
                  <div>
                    <span
                      style="
                        text-decoration: line-through;
                        color: #868e96;
                        margin-right: 6px;
                      "
                    >
                      <?php echo $currency_symbol .
                        wc_format_decimal($original_price, 2); ?>
          
                    </span>
                    <span style="color: #d94000; font-weight: bold"> 
                      <?php echo $currency_symbol .
                        wc_format_decimal($offer_price, 2); ?></span>
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
              <span style="color: #d9480f; font-weight: 700">
                <?php echo $currency_symbol .
                  wc_format_decimal($total_bundle_discounted_price, 2); ?>
              </span>
            </div>
            <div
              style="
                color: #40c057;
                font-weight: 600;
                font-size: 13px;
                display: inline-block;
              "
            >
              You Save: <?php echo $currency_symbol .
                wc_format_decimal($total_saved, 2); ?>!
            </div>
          </div>
        </div>
      </div>

    </div>

    <script type="text/javascript">
      document.addEventListener("DOMContentLoaded", function () {
        const bumpCheckbox = document.getElementById("add_custom_bundle_bump");

        if (!bumpCheckbox) return;

        document.body.addEventListener("change", function (e) {
          if (e.target && e.target.id !== "add_custom_bundle_bump") return;
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

# 3) Dynamic inject | remove the products and override prices based on session state

add_action(
  "woocommerce_before_calculate_totals",
  "dx_apply_bundle_offer_order_bump_rules",
  10,
  1,
);

function dx_apply_bundle_offer_order_bump_rules(object $cart)
{
  # Avoid running the function in administrative backend screen (unless doing an AJAX request)
  if (is_admin() && !defined("DOING_AJAX")) {
    return;
  }

  # Avoid nesting infinite actions during calculation loops
  if (did_action("woocommerce_before_calculate_totals") > 1) {
    return;
  }

  $bump_matrix = dx_get_bundle_order_bump_config();

  $active_bundle_products = [];
  $cart_category_slugs = [];

  # 3.A) Parse what is in the cart naturally
  $natural_products_in_cart = [];
  foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
    $product_id = $cart_item["product_id"];

    if (!isset($cart_item["is_bundle_bump"])) {
      $natural_products_in_cart[] = $product_id;

      # Collect categories for validation
      $terms = get_the_terms($product_id, "product_cat");
      if (!is_wp_error($terms) && !empty($terms)) {
        foreach ($terms as $term) {
          $cart_category_slugs[$term->slug] = true;
        }
      }
    }

    # 3.B) Determine which bundle rule applies based on priority
    uasort($bump_matrix, function ($a, $b) {
      return $a["priority"] <=> $b["priority"];
    });

    foreach ($bump_matrix as $category_slug => $bundle_data) {
      if (isset($cart_category_slugs[$category_slug])) {
        $active_bundle_products = $bundle_data["products"];
        break;
      }
    }
  }
  # TODO: Even if ONE product of the bundle offer is already in the cart naturally, drop everything.
  $has_overlapping_product = dx_cart_has_overlapping_bundle_products(
    $active_bundle_products,
  );

  # TODO: If no target category is present OR there's overlap, reset session state to false.
  if (empty($active_bundle_products) || $has_overlapping_product) {
    WC()->session->set("apply_bundle_bump", false);
    $should_have_bundle = false;
  } else {
    $should_have_bundle = WC()->session->get("apply_bundle_bump");
  }

  # 3.C) Handle Active Injection & Price Override
  if ($should_have_bundle && !empty($active_bundle_products)) {
    foreach ($active_bundle_products as $product_id => $offer_price) {
      # Generate standard target unique key for our custom item stamp
      $custom_cart_id = $cart->generate_cart_id(
        $product_id,
        0,
        [],
        ["is_bundle_bump" => true],
      );
      $cart_item_key = $cart->find_product_in_cart($custom_cart_id);

      if (!$cart_item_key) {
        # Prevent trigger loop by wrapping add_to_cart safely
        $cart->add_to_cart($product_id, 1, 0, [], ["is_bundle_bump" => true]);
      } else {
        // TODO: Clamp quantities tightly to 1 to bypass checkout step exploits
        if ($cart->cart_contents[$cart_item_key]["quantity"] != 1) {
          $cart->set_quantity($cart_item_key, 1, false);
        }
      }
    }

    # Secondary execution block to safely map prices across calculated entities
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
      if (
        isset($cart_item["is_bundle_bump"]) &&
        isset($active_bundle_products[$cart_item["product_id"]])
      ) {
        $discounted_price = $active_bundle_products[$cart_item["product_id"]];
        $cart_item["data"]->set_price($discounted_price);
      }
    }
  }
  # 3.D) TODO: When no trigger category is present, do not remove items.
  # Instead, strip out the bundle tag so they calculate back at normal retail prices.
  else {
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
      if (
        isset($cart_item["is_bundle_bump"]) &&
        $cart_item["is_bundle_bump"] === true
      ) {
        $cart->remove_cart_item($cart_item_key);

        # Force WooCommerce to fall back to regular price settings on the core product data object
        // $cart->cart_contents[$cart_item_key]["data"]->set_price($cart_item["data"]->get_regular_price());
      }
    }
  }
}
# 4) Handle cart item removal
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
    isset($removed_item["is_bundle_bump"]) &&
    $removed_item["is_bundle_bump"] === true
  ) {
    WC()->session->set("apply_bundle_bump", false);
  }
}
