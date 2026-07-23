<?php
// astra-child/inc/OfferShortcode.php
// Refactored: done
/**
 * Offer shortcode — [oto_offer] — renders the current OTO step's offer,
 * which is meant to be dropped into a blank/Canvas-template page
 * built in Elementor (or any other builder).
 *
 * Reads the oto_token query arg, verifies it, and renders the
 * offer if valid.
 * Presentation-agnostic: markup here is minimal
 * and unstyled on purpose — the builder page around it supplies
 * the actual design.
 *
 * File location: astra-child/inc/class-offer-shortcode.php
 * Loaded via:    astra-child/functions.php
 */

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OTO_OfferShortcode
{
  public static function init()
  {
    add_shortcode("oto_offer", [__CLASS__, "render"]);
    add_action("wp_enqueue_scripts", [__CLASS__, "enqueue_assets"]);
  }

  /*
  # INFO: 
  |> Enqueues the click-handling JS and localizes the data it needs (AJAX URL, nonce, translated strings). 
  |> Enqueued on every front-end page load rather than conditionally checking for the shortcode's presence — page builders often don't store content in post_content in a way has_shortcode() can reliably detect, so this trades a small unconditional script load for certainty the offer page always has what it needs. 
  |> The script itself no-ops immediately if .oto-offer isn't present on the page.
  */
  public static function enqueue_assets()
  {
    wp_enqueue_script(
      "oto-offer-page",
      get_stylesheet_directory_uri() . "/assets/js/offer-page.js",
      [],
      "1.0.0",
      true, // Run in footer -> true
    );

    wp_localize_script("oto-offer-page", "otoOfferData", [
      "ajaxUrl" => admin_url("admin-ajax.php"),
      "nonce" => wp_create_nonce(OTO_AjaxHandler::NONCE_ACTION),
      "i18n" => [
        "processing" => __('Please wait\xe2\x80\xa6', "astra-child"),
        "alreadyProcessed" => __(
          "This offer has already been processed.",
          "astra-child",
        ),
        "genericError" => __(
          "Something went wrong. Please try again.",
          "astra-child",
        ),
      ],
    ]);
  }

  /**
   * Shortcode render callback.
   *
   * @return string HTML.
   */
  public static function render()
  {
    $raw_token = isset($_GET["oto_token"])
      ? sanitize_text_field(wp_unslash($_GET["oto_token"]))
      : ""; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if (empty($raw_token)) {
      return self::render_error_state(__("No offer specified.", "astra-child"));
    }

    $context = OTO_OfferResolver::resolve($raw_token);

    if (is_wp_error($context)) {
      return self::render_error_state($context->get_error_message());
    }

    # Log the view now — after resolution succeeds, so a broken or expired token never pollutes wp_oto_events (wp_ + oto_events) with a phantom 'viewed' row for an offer that was never shown.
    OTO_EventsTable::log_viewed([
      "order_id" => $context["token"]["order_id"],
      "chain_id" => $context["token"]["chain_id"],
      "offer_id" => $context["token"]["offer_id"],
      "step" => $context["token"]["step"],
      "offer_type" => self::resolve_offer_type(
        $context["token"],
        $context["offer"],
      ),
      "session_token" => $context["token"]["raw_token"],
    ]);

    return self::render_offer($context);
  }

  /**
   * Maps a token + offer into the offer_type string EventsTable
   * expects — 'downsell' takes priority over the offer's own
   * type, since a bundle shown as a downsell is still logged as
   * a downsell event first and foremost.
   *
   * @param array $token
   * @param array $offer
   * @return string 'oto' | 'bundle' | 'downsell'
   */
  private static function resolve_offer_type(array $token, array $offer)
  {
    if (!empty($token["is_downsell"])) {
      return "downsell";
    }

    return "bundle" === $offer["type"] ? "bundle" : "oto";
  }

  /**
   * Renders the actual offer: product(s), pricing, and
   * Accept/Decline actions. The token is embedded so the AJAX
   * handler receives it back on either action.
   *
   * @param array $context {token: array, order: WC_Order, offer: array}
   * @return string
   */
  private static function render_offer(array $context)
  {
    $offer = $context["offer"];
    $token = $context["token"];
    $pricing = OTO_Pricing::calculate($offer);
    $products = array_filter(
      array_map("wc_get_product", $offer["product_ids"]),
    );

    ob_start();
    ?>
		<div class="oto-offer" data-oto-token="<?php echo esc_attr(
    $token["raw_token"],
  ); ?>">

			<div class="oto-offer__products">
				<?php foreach ($products as $product): ?>
					<div class="oto-offer__product">
						<?php echo wp_kses_post($product->get_image("medium")); ?>
						<h3 class="oto-offer__product-title">
							<?php echo esc_html($product->get_name()); ?>
						</h3>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="oto-offer__pricing">
				<?php if ($pricing["savings"] > 0): ?>
					<span class="oto-offer__price-original">
						<del><?php echo wp_kses_post(wc_price($pricing["original_total"])); ?></del>
					</span>
				<?php endif; ?>

				<span class="oto-offer__price-final">
					<?php echo wp_kses_post(wc_price($pricing["final_total"])); ?>
				</span>

				<?php if ($pricing["savings"] > 0): ?>
					<span class="oto-offer__savings">
              <?php /* translators: %s: amount saved, formatted as currency. */
              echo wp_kses_post(
                sprintf(
                  __("You save %s", "astra-child"),
                  wc_price($pricing["savings"]),
                ),
              ); ?>
					</span>
				<?php endif; ?>
			</div>

      <!-- Action buttons: "Accept" and "Decline" -->
			<div class="oto-offer__actions">
        <!-- Accept button -->
				<button
					type="button"
					class="oto-offer__accept"
					data-oto-action="accept"
					data-oto-token="<?php echo esc_attr($token["raw_token"]); ?>"
				>
					<?php esc_html_e("Yes, add this to my order", "astra-child"); ?>
				</button>

        <!-- Decline button -->
				<button
					type="button"
					class="oto-offer__decline"
					data-oto-action="decline"
					data-oto-token="<?php echo esc_attr($token["raw_token"]); ?>"
				>
					<?php esc_html_e("No thanks", "astra-child"); ?>
				</button>
			</div>

		</div>
		<?php return ob_get_clean();
  }

  /**
   * Renders a plain, on-brand-ish fallback message for invalid,
   * expired, or malformed tokens — never a raw error or a blank
   * page, since this is the first thing a paying customer sees
   * if something's wrong.
   *
   * @param string $message
   * @return string
   */
  private static function render_error_state(string $message)
  {
    ob_start(); ?>
		<div class="oto-offer oto-offer--error">
			<p><?php echo esc_html($message); ?></p>
			<p>
				<a href="<?php echo esc_url(home_url("/")); ?>">
					<?php esc_html_e("Continue to homepage", "astra-child"); ?>
				</a>
			</p>
		</div>
		<?php return ob_get_clean();
  }
}
