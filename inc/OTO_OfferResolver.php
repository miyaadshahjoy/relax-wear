<?php
/**
 * Offer resolver — verifies a raw token's signature, expiry, and
 * then cross-checks it against the database (order and offer both
 * still exist and are valid).
 *
 * This was originally inline in Controller\OfferShortcode. Pulled
 * out because the AJAX accept/decline handler needs the identical
 * check — duplicating it would let the two silently drift apart
 * the first time either one changes.
 *
 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OTO_OfferResolver
{
  /**
   * Verifies a raw token and resolves it against the database.
   *
   * @param string $raw_token
   * @return array|WP_Error {
   *     @type array    $token Decoded token payload (see Token::verify()).
   *     @type WC_Order $order
   *     @type array    $offer Offer row from Model\OffersTable::get().
   * }
   */
  public static function resolve(string $raw_token)
  {
    $token = OtoToken::verify($raw_token);

    if (is_wp_error($token)) {
      return $token;
    }

    $order = wc_get_order($token["order_id"]);

    if (!($order instanceof WC_Order)) {
      return new WP_Error(
        "oto_order_not_found",
        __("We could not find your order.", "your-plugin"),
      );
    }

    $offer = OtoOffersTable::get($token["offer_id"]);

    if (!$offer) {
      return new WP_Error(
        "oto_offer_not_found",
        __("This offer is no longer available.", "your-plugin"),
      );
    }

    if (!$offer["active"]) {
      return new WP_Error(
        "oto_offer_inactive",
        __("This offer is no longer available.", "your-plugin"),
      );
    }

    return [
      "token" => $token,
      "order" => $order,
      "offer" => $offer,
    ];
  }
}
