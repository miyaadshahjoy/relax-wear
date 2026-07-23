<?php
// /astra-child/inc/OTO_FunnelController.php
// Refactoring: done
/**
 * Funnel controller — hooks into order completion and decides
 * whether an OTO funnel applies to this order, redirecting the
 * customer to the first valid step if so.
 *
 * Entry point only: does not render offer pages or handle
 * accept/decline.
 *
 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OTO_FunnelController
{
  /**
   * Order meta key used to guard against re-triggering the funnel
   * if the customer revisits their order-received URL later.
   */
  const STARTED_META_KEY = "_oto_funnel_started";

  public static function init()
  {
    // template_redirect fires early enough to redirect before any
    // HTML output starts — woocommerce_thankyou fires mid-render,
    // by which point headers are already sent.
    add_action("template_redirect", [__CLASS__, "maybe_start_funnel"]);
  }

  /**
   * Entry point. Checks whether we're on an order-received page for
   * an order that hasn't already started its funnel, and if so,
   * hands off to the chain-resolution logic.
   */
  public static function maybe_start_funnel()
  {
    if (
      !function_exists("is_order_received_page") ||
      !is_order_received_page()
    ) {
      return;
    }

    $order_id = absint(get_query_var("order-received"));

    if (!$order_id) {
      return;
    }

    $order = wc_get_order($order_id);

    if (!$order) {
      return;
    }

    if ($order->get_meta(self::STARTED_META_KEY)) {
      return;
    }

    self::resolve_and_redirect($order);
  }

  /**
   * Looks up the matching chain for this order, walks forward
   * through its steps to find the first one worth showing, and
   * hands off to the redirect step.
   *
   * @param WC_Order $order
   */
  private static function resolve_and_redirect(WC_Order $order)
  {
    $order_product_ids = self::get_order_product_ids($order);

    $chain = OTO_ChainsTable::get_chain_for_products($order_product_ids);

    if (!$chain) {
      return; // No OTO chain applies to this order.
    }

    $starting_point = OTO_ChainNavigator::find_next_valid_step(
      $chain,
      1,
      $order_product_ids,
    );

    if (!$starting_point) {
      return; // Every step's offer is already owned — nothing to show.
    }

    self::redirect_to_step(
      $order,
      $chain,
      $starting_point["step"],
      $starting_point["offer"],
    );
  }

  /**
   * Marks the order as having started its funnel, generates a
   * signed token for the given step, and redirects the customer
   * to the offer page.
   *
   * @param WC_Order $order
   * @param array    $chain
   * @param int      $step
   * @param array    $offer
   */
  private static function redirect_to_step(
    WC_Order $order,
    array $chain,
    int $step,
    array $offer,
  ) {
    // Set the guard *before* redirecting — see prior discussion
    // on why this ordering matters for abandoned redirects.
    $order->update_meta_data(self::STARTED_META_KEY, time());
    $order->save();

    $token_url = OTO_Token::build_offer_url(OTO_OfferUrl::page_url($offer), [
      "order_id" => $order->get_id(),
      "chain_id" => $chain["id"],
      "offer_id" => $offer["id"],
      "step" => $step,
      "is_downsell" => false,
    ]);

    wp_safe_redirect($token_url);
    exit();
  }

  /**
   * Returns every product ID in the order (parent product ID, even
   * for variations).
   *
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
