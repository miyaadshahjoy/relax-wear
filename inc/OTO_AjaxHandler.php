<?php
/**
 * AJAX handler — processes accept/decline actions from the offer
 * page. Registers both wp_ajax_ and wp_ajax_nopriv_ variants since
 * most WooCommerce customers checking out are guests, not logged
 * in — omitting nopriv would silently break this for them.
 *
 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OTO_AjaxHandler
{
  /**
   * Nonce action name — must match what's localized to the JS
   * (built in a later step).
   */
  const NONCE_ACTION = "oto_offer_action";

  public static function init()
  {
    add_action("wp_ajax_oto_accept", [__CLASS__, "handle_accept"]);
    add_action("wp_ajax_nopriv_oto_accept", [__CLASS__, "handle_accept"]);

    add_action("wp_ajax_oto_decline", [__CLASS__, "handle_decline"]);
    add_action("wp_ajax_nopriv_oto_decline", [__CLASS__, "handle_decline"]);
  }

  /**
   * Handles an "accept" request: claims the event (replay guard),
   * adds the offer's product(s) to the order at the offer's price,
   * and responds with where the customer should go next.
   */
  public static function handle_accept()
  {
    $context = self::authenticate_request();

    if (is_wp_error($context)) {
      wp_send_json_error(["message" => $context->get_error_message()], 400);
    }

    $token = $context["token"];
    $order = $context["order"];
    $offer = $context["offer"];

    $line_items = Pricing::get_line_items($offer);
    $total = array_sum(wp_list_pluck($line_items, "total"));

    // Claim the event BEFORE touching the order. log_outcome()
    // only succeeds while the row is still 'viewed' — if this
    // token was already accepted or declined (double-click,
    // replayed request), it returns false here and we stop
    // before adding the product a second time.
    $claimed = EventsTable::log_outcome(
      $token["raw_token"],
      "accepted",
      $total,
    );

    if (!$claimed) {
      wp_send_json_error(
        [
          "message" => __(
            "This offer has already been processed.",
            "your-plugin",
          ),
        ],
        409,
      );
    }

    foreach ($line_items as $line) {
      $product = wc_get_product($line["product_id"]);

      if (!$product) {
        continue; // Already validated as existing in OfferResolver's product lookups upstream; defensive only.
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

  /**
   * Handles a "decline" request: claims the event (replay guard),
   * then either shows the offer's downsell (if one exists, is
   * active, and isn't already owned) or advances straight to the
   * next chain step — same "what's next" logic accept uses.
   */
  public static function handle_decline()
  {
    $context = self::authenticate_request();

    if (is_wp_error($context)) {
      wp_send_json_error(["message" => $context->get_error_message()], 400);
    }

    $token = $context["token"];
    $order = $context["order"];
    $offer = $context["offer"];

    // Claim the event before deciding what's next — same reasoning
    // as accept: a replayed decline should be a no-op, not a second
    // downsell/advance decision.
    $claimed = EventsTable::log_outcome($token["raw_token"], "declined", 0.0);

    if (!$claimed) {
      wp_send_json_error(
        [
          "message" => __(
            "This offer has already been processed.",
            "your-plugin",
          ),
        ],
        409,
      );
    }

    $downsell_response = self::maybe_build_downsell_response(
      $order,
      $token,
      $offer,
    );

    if ($downsell_response) {
      wp_send_json_success($downsell_response);
    }

    // No downsell (or it wasn't showable) — proceed to the next
    // real step in the chain, regardless of this decline.
    wp_send_json_success(self::build_next_step_response($order, $token));
  }

  /**
   * Checks whether this offer has a downsell worth showing, and if
   * so, builds the response pointing at it. Returns null if there's
   * no downsell configured, it's inactive, or its product is already
   * in the order — any of which means "skip straight to the next step."
   *
   * A downsell is shown at the SAME step number as the offer it
   * followed, marked is_downsell — it's a detour, not an advance.
   *
   * @param WC_Order $order
   * @param array    $token
   * @param array    $offer
   * @return array|null
   */
  private static function maybe_build_downsell_response(
    WC_Order $order,
    array $token,
    array $offer,
  ) {
    if (empty($offer["downsell_offer_id"])) {
      return null;
    }

    $downsell = OffersTable::get($offer["downsell_offer_id"]);

    if (!$downsell || !$downsell["active"]) {
      return null;
    }

    $owned_product_ids = self::get_order_product_ids($order);
    $already_owned = (bool) array_intersect(
      $downsell["product_ids"],
      $owned_product_ids,
    );

    if ($already_owned) {
      return null;
    }

    $downsell_url = Token::build_offer_url(OfferUrl::page_url($downsell), [
      "order_id" => $token["order_id"],
      "chain_id" => $token["chain_id"],
      "offer_id" => $downsell["id"],
      "step" => $token["step"],
      "is_downsell" => true,
    ]);

    return [
      "redirect_url" => $downsell_url,
      "funnel_complete" => false,
      "is_downsell" => true,
    ];
  }

  /**
   * Common request validation shared by both actions: nonce check,
   * pull and sanitize the token, then resolve it via OfferResolver.
   *
   * @return array|WP_Error Resolved context on success.
   */
  private static function authenticate_request()
  {
    $nonce_valid = check_ajax_referer(self::NONCE_ACTION, "nonce", false);

    if (!$nonce_valid) {
      return new WP_Error(
        "oto_bad_nonce",
        __("Your session has expired. Please refresh the page.", "your-plugin"),
      );
    }

    $raw_token = isset($_POST["oto_token"])
      ? sanitize_text_field(wp_unslash($_POST["oto_token"]))
      : "";

    if (empty($raw_token)) {
      return new WP_Error(
        "oto_missing_token",
        __("No offer token was provided.", "your-plugin"),
      );
    }

    return OfferResolver::resolve($raw_token);
  }

  /**
   * Figures out where the customer should go after this step —
   * the next valid OTO step in the chain, or the thank-you page
   * if the chain is exhausted. Shared by accept and decline once
   * decline's downsell branch is added, since both eventually
   * need "what's next after this step."
   *
   * @param WC_Order $order
   * @param array    $token
   * @return array {redirect_url: string, funnel_complete: bool}
   */
  private static function build_next_step_response(
    WC_Order $order,
    array $token,
  ) {
    $chain = ChainsTable::get($token["chain_id"]);

    if (!$chain) {
      return [
        "redirect_url" => $order->get_checkout_order_received_url(),
        "funnel_complete" => true,
      ];
    }

    $owned_product_ids = self::get_order_product_ids($order);
    $next = ChainNavigator::find_next_valid_step(
      $chain,
      $token["step"] + 1,
      $owned_product_ids,
    );

    if (!$next) {
      return [
        "redirect_url" => $order->get_checkout_order_received_url(),
        "funnel_complete" => true,
      ];
    }

    $next_url = Token::build_offer_url(OfferUrl::page_url($next["offer"]), [
      "order_id" => $order->get_id(),
      "chain_id" => $chain["id"],
      "offer_id" => $next["offer"]["id"],
      "step" => $next["step"],
      "is_downsell" => false,
    ]);

    return [
      "redirect_url" => $next_url,
      "funnel_complete" => false,
    ];
  }

  /**
   * @param WC_Order $order
   * @return array
   */
  private static function get_order_product_ids(WC_Order $order)
  {
    $product_ids = [];

    foreach ($order->get_items() as $item) {
      /** @var \WC_Order_Item_Product $item */
      $product_ids[] = $item->get_product_id();
    }

    return array_unique(array_filter(array_map("absint", $product_ids)));
  }
}
