<?php
// /astra-child/inc/OTO_Pricing.php
// Refactoring: done
/**
 * Pricing service — computes an offer's original total, final
 * price, and savings from its price_type/price_value, based on
 * the live prices of its product(s).
 *
 * Shared between the offer page (display) and the AJAX
 * accept/decline handler (the actual charge/order amount) so the
 * two can never drift out of sync with each other.
 *
 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OTO_Pricing
{
  /**
   * Calculates pricing for an offer.
   *
   * @param array $offer Offer row from Model\OffersTable::get().
   * @return array {
   *     @type float $original_total Sum of the product(s)' regular prices.
   *     @type float $final_total    What the customer actually pays.
   *     @type float $savings        original_total - final_total, floored at 0.
   * }
   */
  public static function calculate(array $offer)
  {
    $original_total = self::get_products_total($offer["product_ids"]);

    switch ($offer["price_type"]) {
      case "percent_off":
        $final_total =
          $original_total * (1 - (float) $offer["price_value"] / 100);
        break;

      case "bundle_price":
      case "fixed":
        $final_total = (float) $offer["price_value"];
        break;

      default:
        // Unknown price_type — fall back to charging full price
        // rather than guessing, so a config mistake can't
        // accidentally give a product away for free.
        $final_total = $original_total;
        break;
    }

    $final_total = max(0, round($final_total, 2));
    $savings = max(0, round($original_total - $final_total, 2));

    return [
      "original_total" => round($original_total, 2),
      "final_total" => $final_total,
      "savings" => $savings,
    ];
  }

  /**
   * Allocates the offer's final_total across each of its products,
   * proportional to each product's share of the original combined
   * price. Needed because WooCommerce order line items are priced
   * per-product — a bundle's single combined discount has to be
   * split across its N line items, not applied once.
   *
   * The split can leave a rounding remainder (each line rounded to
   * 2dp independently); that remainder is folded into the last
   * line item so the sum of all lines always equals final_total
   * exactly — order totals must reconcile to the cent.
   *
   * @param array $offer Offer row from Model\OffersTable::get().
   * @return array List of {product_id, subtotal, total} — subtotal
   *               is this product's regular price (pre-discount),
   *               total is its share of the discounted price.
   */
  public static function get_line_items(array $offer)
  {
    $pricing = self::calculate($offer);
    $product_ids = $offer["product_ids"];
    $line_items = [];
    $allocated_so_far = 0.0;

    foreach ($product_ids as $index => $product_id) {
      $product = wc_get_product($product_id);

      if (!$product) {
        continue;
      }

      $regular_price = (float) $product->get_regular_price();
      $is_last = $index === array_key_last($product_ids);

      if ($is_last) {
        // Give the last item whatever's left, so the total
        // always reconciles exactly rather than being off by
        // a cent from independent rounding.
        $line_total = round($pricing["final_total"] - $allocated_so_far, 2);
      } else {
        $share =
          $pricing["original_total"] > 0
            ? $regular_price / $pricing["original_total"]
            : 1 / count($product_ids);
        $line_total = round($pricing["final_total"] * $share, 2);
      }

      $allocated_so_far += $line_total;

      $line_items[] = [
        "product_id" => $product_id,
        "subtotal" => $regular_price,
        "total" => max(0, $line_total),
      ];
    }

    return $line_items;
  }

  /**
   * Sums the regular price of every product in the offer.
   *
   * @param array $product_ids
   * @return float
   */
  private static function get_products_total(array $product_ids)
  {
    $total = 0.0;

    foreach ($product_ids as $product_id) {
      $product = wc_get_product($product_id);

      if (!$product) {
        continue; // Skip silently rather than fatal — caller should still get a usable total.
      }

      $total += (float) $product->get_regular_price();
    }

    return $total;
  }
}
