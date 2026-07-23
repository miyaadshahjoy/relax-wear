<?php
// /astra-child/inc/OTO_AjaxHandler.php
// Refactoring: done
/**
 * AJAX handler — processes accept/decline actions from the offer
 * page. Registers both wp_ajax_ and wp_ajax_nopriv_ variants since
 * most WooCommerce customers checking out are guests, not logged
 * in — omitting nopriv would silently break this for them.
 *
 */

if (!defined("ABSPATH")) {
  exit();
}

class OTO_AjaxHandler
{
  const NONCE_ACTION = "oto_offer_action";

  public static function init()
  {
    add_action("wp_ajax_oto_accept", [__CLASS__, "handle_accept"]);
    add_action("wp_ajax_nopriv_oto_accept", [__CLASS__, "handle_accept"]);
    add_action("wp_ajax_oto_decline", [__CLASS__, "handle_decline"]);
    add_action("wp_ajax_nopriv_oto_decline", [__CLASS__, "handle_decline"]);
  }

  public static function handle_accept()
  {
    $context = self::authenticate_request();
    if (is_wp_error($context)) {
      wp_send_json_error(["message" => $context->get_error_message()], 400);
    }

    $token = $context["token"];
    $order = $context["order"];
    $offer = $context["offer"];
    $line_items = OTO_Pricing::get_line_items($offer);
    $total = array_sum(wp_list_pluck($line_items, "total"));

    $claimed = OTO_EventsTable::log_outcome(
      $token["raw_token"],
      "accepted",
      $total,
    );
    if (!$claimed) {
      wp_send_json_error(
        [
          "message" => __(
            "This offer has already been processed.",
            "astra-child",
          ),
        ],
        409,
      );
      return;
    }

    foreach ($line_items as $line) {
      $product = wc_get_product($line["product_id"]);
      if (!$product) {
        continue;
      }
      $order->add_product($product, 1, [
        "subtotal" => $line["subtotal"],
        "total" => $line["total"],
      ]);
    }
    $order->calculate_totals();
    $order->save();

    wp_send_json_success(self::build_next_step_response($order, $token));
  }

  public static function handle_decline()
  {
    $context = self::authenticate_request();
    if (is_wp_error($context)) {
      wp_send_json_error(["message" => $context->get_error_message()], 400);
    }

    $token = $context["token"];
    $order = $context["order"];
    $offer = $context["offer"];

    $claimed = OTO_EventsTable::log_outcome(
      $token["raw_token"],
      "declined",
      0.0,
    );
    if (!$claimed) {
      wp_send_json_error(
        [
          "message" => __(
            "This offer has already been processed.",
            "astra-child",
          ),
        ],
        409,
      );
      return;
    }

    $downsell_response = self::maybe_build_downsell_response(
      $order,
      $token,
      $offer,
    );
    if ($downsell_response) {
      wp_send_json_success($downsell_response);
    }

    wp_send_json_success(self::build_next_step_response($order, $token));
  }

  private static function authenticate_request()
  {
    $nonce_valid = check_ajax_referer(self::NONCE_ACTION, "nonce", false);
    if (!$nonce_valid) {
      return new WP_Error(
        "oto_bad_nonce",
        __("Your session has expired. Please refresh the page.", "astra-child"),
      );
    }
    $raw_token = isset($_POST["oto_token"])
      ? sanitize_text_field(wp_unslash($_POST["oto_token"]))
      : "";
    if (empty($raw_token)) {
      return new WP_Error(
        "oto_missing_token",
        __("No offer token was provided.", "astra-child"),
      );
    }
    return OTO_OfferResolver::resolve($raw_token);
  }

  private static function maybe_build_downsell_response(
    WC_Order $order,
    array $token,
    array $offer,
  ) {
    if (empty($offer["downsell_offer_id"])) {
      return null;
    }

    $downsell = OTO_OffersTable::get($offer["downsell_offer_id"]);
    if (!$downsell || !$downsell["active"]) {
      return null;
    }

    $owned = self::get_order_product_ids($order);
    if ((bool) array_intersect($downsell["product_ids"], $owned)) {
      return null;
    }

    return [
      "redirect_url" => OTO_Token::build_offer_url(
        OTO_OfferUrl::page_url($downsell),
        [
          "order_id" => $token["order_id"],
          "chain_id" => $token["chain_id"],
          "offer_id" => $downsell["id"],
          "step" => $token["step"],
          "is_downsell" => true,
        ],
      ),
      "funnel_complete" => false,
      "is_downsell" => true,
    ];
  }

  private static function build_next_step_response(
    WC_Order $order,
    array $token,
  ) {
    $chain = OTO_ChainsTable::get($token["chain_id"]);
    if (!$chain) {
      return [
        "redirect_url" => $order->get_checkout_order_received_url(),
        "funnel_complete" => true,
      ];
    }

    $owned = self::get_order_product_ids($order);
    $next = OTO_ChainNavigator::find_next_valid_step(
      $chain,
      $token["step"] + 1,
      $owned,
    );

    if (!$next) {
      return [
        "redirect_url" => $order->get_checkout_order_received_url(),
        "funnel_complete" => true,
      ];
    }

    return [
      "redirect_url" => OTO_Token::build_offer_url(
        OTO_OfferUrl::page_url($next["offer"]),
        [
          "order_id" => $order->get_id(),
          "chain_id" => $chain["id"],
          "offer_id" => $next["offer"]["id"],
          "step" => $next["step"],
          "is_downsell" => false,
        ],
      ),
      "funnel_complete" => false,
    ];
  }

  private static function get_order_product_ids(WC_Order $order)
  {
    $product_ids = [];
    foreach ($order->get_items() as $item) {
      $product_ids[] = $item->get_product_id();
    }
    return array_unique(array_filter(array_map("absint", $product_ids)));
  }
}
