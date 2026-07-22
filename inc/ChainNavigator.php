<?php
/**
 * Chain navigator — walks a chain's steps starting from a given
 * point, skipping any step whose offer is inactive or already
 * owned, until it finds a valid step or runs out.
 *
 * Originally inline in Controller\FunnelController (which only
 * ever needed to start from step 1). Extracted because the AJAX
 * handler needs the identical logic to find the *next* step after
 * whichever one was just accepted or declined — same walk, just a
 * different starting point.
 *

 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class ChainNavigator
{
  /**
   * Finds the first valid step at or after $from_step.
   *
   * @param array $chain              Chain row.
   * @param int   $from_step          Step to start checking from (1-3).
   * @param array $owned_product_ids  Product IDs already in the order —
   *                                  steps whose offer overlaps these are skipped.
   * @return array|null {step, offer} or null if no valid step remains.
   */
  public static function find_next_valid_step(
    array $chain,
    int $from_step,
    array $owned_product_ids,
  ) {
    if ($from_step > 3) {
      return null; // Ran out of steps.
    }

    $offer_id = OtoChainsTable::get_step_offer_id($chain, $from_step);

    if (!$offer_id) {
      return null; // This chain doesn't have a step this deep.
    }

    $offer = OtoOffersTable::get($offer_id);

    if (!$offer || !$offer["active"]) {
      return self::find_next_valid_step(
        $chain,
        $from_step + 1,
        $owned_product_ids,
      );
    }

    $already_owned = (bool) array_intersect(
      $offer["product_ids"],
      $owned_product_ids,
    );

    if ($already_owned) {
      return self::find_next_valid_step(
        $chain,
        $from_step + 1,
        $owned_product_ids,
      );
    }

    return [
      "step" => $from_step,
      "offer" => $offer,
    ];
  }
}
