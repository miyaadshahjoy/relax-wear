<?php
// /astra-child/inc/OTO_OfferUrl.php
// Refactoring: done
/**
 * Offer URL resolver — resolves an offer's base page URL (before
 * a token is attached). Originally inline in Controller\FunnelController;
 * extracted because the AJAX handler needs to build a URL for
 * whatever the *next* step's offer is, and a downsell's, using the
 * identical logic.
 *
 * Adjust page_url() to match your actual offer-page structure — a
 * single generic template vs. one page per offer.
 *
 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OTO_OfferUrl
{
  /**
   * @param array $offer
   * @return string
   */
  public static function page_url(array $offer)
  {
    # info: add_query_arg() -> used to rebuild an existing URL by appending a new query arg
    # add_query_arg( 'key', 'value', 'http://example.com' );

    # info: home_url() -> returns the site's home URL
    # home_url("/oto-offer/"); -> returns the site's home URL + "/oto-offer/"
    return add_query_arg("oto_offer", $offer["id"], home_url("/oto-offer/"));
  }
}
