<?php
/**
 * Astra Child Theme functions and definitions
 */

function astra_child_enqueue_styles()
{
  // Load the parent theme's stylesheet
  wp_enqueue_style("parent-style", get_template_directory_uri() . "/style.css");
}
add_action("wp_enqueue_scripts", "astra_child_enqueue_styles");

require_once "inc/order-bump.php";
// require_once "inc/bundle-offer.php";

############################################################################################
/* 
    |> add_shortcode('shortcode_name', 'function_name');
    |> add_shortcode( ) -> Adds a custom shortcode that can be used in posts, pages, widgets, etc. 
*/
# Shortcode for custom Lead Form
# Defining a function for a custom Lead Form
function dx_custom_lead_form_shortcode()
{
  ob_start(); ?>
    <form id="dx-lead-form" class="dx-lead-form-container" method="POST">
        <div style="display: none !important">
            <input
            type="text"
            name="dx_hp_field"
            id="dx_hp_field"
            tabindex="-1"
            autocomplete="off"
            />
        </div>

        <div class="dx-form-group">
            <label for="lead_name">Name <span class="required">*</span></label>
            <input
            type="text"
            name="lead_name"
            id="lead_name"
            required
            placeholder="Enter your name"
            />
        </div>

        <div class="dx-form-group">
            <label for="lead_phone"
            >Phone <span class="required">*</span></label
            >
            <input
            type="tel"
            name="lead_phone"
            id="lead_phone"
            required
            placeholder="Enter your phone number"
            pattern="^(?:\+88|88)?(01[3-9]\d{8})$"
            />
        </div>

        <div class="dx-form-group">
            <label for="lead_email">Email
            <span class="required">*</span></label>
            <input
            type="email"
            name="lead_email"
            id="lead_email"
            required
            placeholder="Enter your email"
            />
        </div>

        <button type="submit" class="dx-submit-btn">Subscribe</button>
        <div
            id="form-message"
            style="margin-top: 15px; font-weight: bold; display: none"
        ></div>
    </form>
    <?php return ob_get_clean();
}
# Add Shortcode for custom Lead Form
add_shortcode("custom_lead_form", "dx_custom_lead_form_shortcode");

######################################################################

# Enqueue AJAX Script for form submission
# |> wp_footer (Hook) -> Prints scripts or data before the closing body tag on the front end.

add_action("wp_footer", "dx_lead_form_ajax_script");
function dx_lead_form_ajax_script()
{
  ?>
    
    <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function () {
            const form = document.getElementById("dx-lead-form");
            if(!form) return;
            form.addEventListener("submit", async (e) => {
            e.preventDefault();

            // ----------------------------------------------------------
            const submitBtn = document.querySelector(".dx-submit-btn");
            const formMessageContainer = document.getElementById("form-message");

            //////////////////////////////////////////
            submitBtn.disabled = true;
            submitBtn.textContent = "Please wait...";
            formMessageContainer.style.display = "none";

            /*
            * |> FormData (class) -> Creates a javaScript object that can be used to encode form data to be submitted via an HTTP request. 

            * |> An HTML <form> element — when specified, the FormData object will be populated with the form's current keys/values using the name property of each element for the keys and their submitted value for the values.
            * 
            * |> It will also encode file input content. 
            * |> A formdata event is fired on the form when the FormData object is created, allowing the form to modify the formdata if necessary.
            */
            const formData = new FormData(form);
            formData.append("action", "process_custom_lead");

            // Fetch API
            try {
                const response = await fetch(
                "<?php echo admin_url("admin-ajax.php"); ?>",
                {
                    method: "POST",
                    body: formData,
                },
                );
                if (!response.ok) throw new Error("Something went wrong");

                const data = await response.json();
                if (data.success) {
                formMessageContainer.textContent = data.data.message;
                formMessageContainer.style.display = "block";
                formMessageContainer.style.color = "green";
                form.reset();
                } else {
                formMessageContainer.textContent = data.data.message;
                formMessageContainer.style.display = "block";
                formMessageContainer.style.color = "red";
                }
            } catch (error) {
                console.error("Error", error);
                formMessageContainer.textContent =
                "Something went wrong. Please try again.";
                formMessageContainer.style.display = "block";
                formMessageContainer.style.color = "red";
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = "Subscribe";
            }
            });
        });
    </script>

    <?php
}

add_action("wp_ajax_process_custom_lead", "dx_process_custom_lead_form");
add_action("wp_ajax_nopriv_process_custom_lead", "dx_process_custom_lead_form");

function dx_process_custom_lead_form()
{
  # 1) TODO: Security and CSRF check

  # check_ajax_referer($action, $query_arg, $die = true / false);

  # 2) Spam Check: Honeypot
  # |> wp_send_json_error() -> Sends a JSON response back to an Ajax request, indicating failure.
  if (!empty($_POST["dx_hp_field"])) {
    wp_send_json_error(["message" => "Invalid request. Spam detected."]);
  }

  # 3) Validate inputs
  if (
    !isset($_POST["lead_name"]) ||
    !isset($_POST["lead_phone"]) ||
    !isset($_POST["lead_email"])
  ) {
    wp_send_json_error([
      "message" => "Invalid request. Please fill up all the required fields.",
    ]);
  }
  # 4) Sanitize inputs
  $name = sanitize_text_field($_POST["lead_name"]);
  $phone = sanitize_text_field($_POST["lead_phone"]);
  $email = sanitize_email($_POST["lead_email"]);
  $name = trim($name);
  $phone = trim($phone);
  $email = trim($email);

  # Normalize phone number (Remove non-numeric characters for data consistency)
  $phone = preg_replace("/[^0-9]/", "", $phone);

  # Check if phone number is valid
  if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    wp_send_json_error(["message" => "Invalid phone number format."]);
  }

  # Check if email is valid
  if (!is_email($email)) {
    wp_send_json_error(["message" => "Invalid email format."]);
  }

  # 5) Sync to FluentCRM
  if (function_exists("FluentCrmApi")) {
    try {
      $contactApi = FluentCrmApi("contacts");

      # TODO: Split fullname into firstname and lastname
      $name_parts = explode(" ", $name, 2);
      $firstname = $name_parts[0];
      $lastname = isset($name_parts[1]) ? $name_parts[1] : "";

      $data = [
        "first_name" => $firstname,
        "last_name" => $lastname,
        "phone" => $phone,
        "email" => $email,
        "status" => "subscribed",
        "tags" => ["lead-captured"],
      ];

      $contact = $contactApi->createOrUpdate($data);

      if (!$contact) {
        wp_send_json_error(["message" => "Problem saving data to FluentCRM."]);
      }

      wp_send_json_success(["message" => "Data saved to FluentCRM."]);
    } catch (Exception $e) {
      # TODO: Log actual code errors quietly to error_log without exposing details to user
      # |> error_log -> Logs the error to error log file in the server.
      error_log("FluentCRM Sync Error: " . $e->getMessage());

      # Show a generic message to the user
      wp_send_json_error([
        "message" => "Problem saving data to FluentCRM due to system error.",
      ]);
    }
  } else {
    wp_send_json_error(["message" => "FluentCRM plugin is not active."]);
  }
  # Fallback
  wp_die();
}

##############################################################

# Customer journey and purchase tracking automation with FluentCRM
# TODO: Implementing WooCommerce Order Status update and FluentCRM Automation Bridge
/*
# Covers:
    |> Multi-product segmentation: 
    |> Customer Journey Segment
    |> Contact tagging based on Purchase and OTO event
*/

# In WooCommerce, get_items() is used to retrieve the list of products or line items attached to a specific order.

# Multi Product segmentation
// add_action('woocommerce_order_status_processing', 'dx_wc_fluentcrm_automation_bridge', 10, 1);
// add_action('woocommerce_order_status_completed', 'dx_wc_fluentcrm_automation_bridge', 10, 1);

# TODO: Implementing multi-product and customer journey segmentation

add_action(
  "woocommerce_order_status_changed",
  "dx_wc_fluentcrm_segmentation_bridge",
  10,
  4,
);

function dx_wc_fluentcrm_segmentation_bridge(
  $order_id,
  $old_status,
  string $new_status,
  object $order,
) {
  # Only execute when an order is successfully paid | processed
  if (!in_array($new_status, ["processing", "completed"])) {
    return;
  }

  # Check if fluentCRM is active
  if (!function_exists("FluentCrmApi")) {
    return;
  }

  # Check if email is available in order details
  $email = $order->get_billing_email();

  if (empty($email)) {
    return;
  }

  $phone = preg_replace("/[^0-9]/", "", $order->get_billing_phone());

  try {
    $contactApi = FluentCrmApi("contacts");

    $tags = [];
    $lists = [];

    # Funnel mapping matrix (Product | Variation ID => [Tags, Lists] )

    $funnel_map = [
      47 => ["tags" => ["product-1"], "lists" => ["list-1"]],
      49 => ["tags" => ["product-2"], "lists" => ["list-2"]],
      39 => ["tags" => ["product-3"], "lists" => ["list-3"]],
    ];

    # Looping through items to process multi-product segmentation
    foreach ($order->get_items() as $item) {
      $product_id = $item->get_product_id();
      $variation_id = $item->get_variation_id();

      # Determine correct product ID (Fallback to parent ID if not a variation product)

      $target_id = $variation_id ? $variation_id : $product_id;

      # If purchased product ID exists in the funnel mapping matrix, inject the corresponding tags and lists

      if (isset($funnel_map[$target_id])) {
        $tags = array_merge($tags, $funnel_map[$target_id]["tags"]);
        $lists = array_merge($lists, $funnel_map[$target_id]["lists"]);
      }
    }

    # Format submission payload
    $contact_data = [
      "email" => $email,
      "first_name" => $order->get_billing_first_name(),
      "last_name" => $order->get_billing_last_name(),
      "phone" => $phone,
      "status" => "subscribed",
    ];

    if (!empty($tags)) {
      $contact_data["tags"] = array_unique($tags);
    }
    if (!empty($lists)) {
      $contact_data["lists"] = array_unique($lists);
    }

    # Sync/Create contact in FluentCRM
    $contact = $contactApi->createOrUpdate($contact_data);

    if (!$contact) {
      throw new Exception("Failed to create or update contact in FluentCRM.");
    }
  } catch (Exception $e) {
    # Log errors to the log file
    error_log("FluentCRM Segmentation Error: " . $e->getMessage());
  }
}

/*
    # Distraction-Free Checkout Layout Optimization
    # Removed: Address Line 2, Order comments
    # Phone -> required
    # Post code -> required
 */

add_filter(
  "woocommerce_default_address_fields",
  "dx_hide_country_label_globally",
);
function dx_hide_country_label_globally(array $fields)
{
  # Set country field as hidden
  $fields["country"]["type"] = "hidden";
  $fields["country"]["label"] = ""; # Hide label
  $fields["country"]["required"] = false; # Make country field not required
  return $fields;
}

add_filter("woocommerce_checkout_fields", "dx_optimize_checkout_fields");

function dx_optimize_checkout_fields(array $fields)
{
  unset($fields["billing"]["billing_company"]);
  unset($fields["shipping"]["shipping_company"]);

  unset($fields["billing"]["billing_address_2"]);
  unset($fields["shipping"]["shipping_address_2"]);

  unset($fields["order"]["order_comments"]);

  $fields["billing"]["billing_country"]["default"] = "BD";

  $fields["billing"]["billing_postcode"]["required"] = true;
  $fields["billing"]["billing_phone"]["required"] = true;
  $fields["billing"]["billing_state"]["label"] = "Region / Province";
  $fields["billing"]["billing_address_1"]["label"] = "Address";

  $fields["shipping"]["shipping_postcode"]["required"] = true;
  $fields["shipping"]["shipping_phone"]["required"] = true;
  $fields["shipping"]["shipping_state"]["label"] = "Region / Province";
  $fields["shipping"]["shipping_address_1"]["label"] = "Address";

  return $fields;
}
