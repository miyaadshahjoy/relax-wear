<?php
/*
  # OTO Token 
 
  |> Generate and verify a signed, tamper-proof token that identifies
  exactly which (order, chain, step, offer) an offer page is for.
 
  |> This is what stops a customer from:
    - Editing the URL to jump straight to step 3
    - Replaying a step they already accepted/declined
    - Viewing another customer's offer by guessing an order ID
 
  |> The token is self-contained (HMAC-signed, no DB lookup needed to
  validate it) but each token also carries a random nonce, so every
  page view gets a unique session_token — that's what OtoEventsTable
  uses to match a 'viewed' row to its eventual 'accepted'/'declined'
  outcome without two views colliding.

  |> HMAC -> Hash-Based Message Authentication Code
 
  |> Actual replay protection (stopping a valid, unexpired token from
  being submitted twice) happens one layer up: OtoEventsTable::log_outcome()
  only updates a row while its action is still 'viewed'. Once consumed,
  a second submit finds nothing to update. This class only guarantees
  the token itself is genuine and not expired.
 
*/

if (!defined("ABSPATH")) {
  exit(); // No direct access.
}

class OtoToken
{
  /*
   How long a token stays valid, in seconds. Keep this in step
   with OtoEventsTable::mark_abandoned()'s stale-after window —
   there's no point a token outliving the point it gets swept as
   abandoned.
  */
  const TTL_SECONDS = 30 * MINUTE_IN_SECONDS;

  /**
   * Generates a signed token for a specific offer step.
   * Builds a payload with the order ID, chain ID, offer ID, and step number (1|2|3), downsell flag, expiry timestamp and a nonce.
   *
   * @param array $args {
   *     @type int  $order_id
   *     @type int  $chain_id
   *     @type int  $offer_id
   *     @type int  $step 1, 2, or 3.
   *     @type bool $is_downsell Whether this is the downsell variant of the step.
   * }
   * @return string The token, e.g. "eyJvaWQiOj...==.9f3a2b...".
   */
  public static function generate(array $args)
  {
    $payload = [
      "oid" => absint($args["order_id"]),
      "cid" => absint($args["chain_id"]),
      "off" => absint($args["offer_id"]),
      "step" => absint($args["step"]),
      "ds" => !empty($args["is_downsell"]) ? 1 : 0,
      "exp" => time() + self::TTL_SECONDS,
      # Because of nonce, two tokens for the same step are never identical — this is what becomes the unique session_token in wp_oto_events.
      "nce" => wp_generate_password(12, false), # random 12 character nonce
    ];

    # Payload JSON-encoded and also base64url-encoded.
    $payload_encoded = self::base64url_encode(wp_json_encode($payload));
    # HMAC-SHA256 keyed off WordPress's own "secure_auth" salt.
    $signature = self::sign($payload_encoded);

    # Concatenate payload and signature, separated by a dot.
    return $payload_encoded . "." . $signature;
  }

  /*
  |> Verifies a token's signature and expiry, and returns its
   decoded payload if valid.
  
  |> Does NOT check anything against the database — callers still need to confirm the order|chain|offer|step actually match what they expect (e.g. the order really does belong to this chain, the step really is next in sequence). This only proves the token wasn't forged or tampered with, and hasn't expired.
  */

  /**
   * @param string $token
   * @return array|WP_Error Decoded payload on success, WP_Error on failure.
   */
  public static function verify(string $token): array|WP_Error
  {
    # Split token into payload and signature. Rejects anything that isn't exactly 2 parts.
    $parts = explode(".", $token);

    # Token must be exactly 2 parts.
    if (count($parts) !== 2) {
      return new WP_Error(
        "oto_token_malformed",
        __("This offer link is invalid."),
      );
    }

    [$payload_encoded, $signature] = $parts;

    $expected_signature = self::sign($payload_encoded);

    # Timing-safe comparison — do not swap to ===.
    if (!hash_equals($expected_signature, $signature)) {
      return new WP_Error(
        "oto_token_invalid_signature",
        __("This offer link could not be verified.", ""),
      );
    }

    # base64url-decode the payload.
    $json = self::base64url_decode($payload_encoded);
    # JSON-decode the payload.
    $payload = json_decode($json, true);

    if (
      !is_array($payload) ||
      !isset(
        $payload["exp"],
        $payload["oid"],
        $payload["cid"],
        $payload["off"],
        $payload["step"],
      )
    ) {
      return new WP_Error(
        "oto_token_malformed",
        __("This offer link is invalid.", ""),
      );
    }

    if (time() > (int) $payload["exp"]) {
      return new WP_Error(
        "oto_token_expired",
        __("This offer has expired.", ""),
      );
    }

    return [
      "order_id" => (int) $payload["oid"],
      "chain_id" => (int) $payload["cid"],
      "offer_id" => (int) $payload["off"],
      "step" => (int) $payload["step"],
      "is_downsell" => !empty($payload["ds"]),
      "expires_at" => (int) $payload["exp"],

      "raw_token" => $payload_encoded . "." . $signature, # the raw token, for reuse as the event-log session token.
    ];
  }

  /**
   * Builds the full offer-page URL for a given step, with the
   * signed token attached as a query arg.
   *
   * @param string $base_url The offer page's permalink.
   * @param array  $args     Same shape as generate()'s $args.
   * @return string
   */
  public static function build_offer_url(string $base_url, array $args)
  {
    $token = self::generate($args);
    return add_query_arg("oto_token", rawurlencode($token), $base_url);
  }

  /**
   * Signs a payload with HMAC-SHA256, keyed off WordPress's own
   * secure auth salt so no separate secret needs to be stored or
   * rotated manually.
   *
   * @param string $payload_encoded
   * @return string Hex-encoded signature.
   */
  private static function sign(string $payload_encoded)
  {
    return hash_hmac("sha256", $payload_encoded, wp_salt("secure_auth"));
  }

  private static function base64url_encode(string $data)
  {
    return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
  }

  private static function base64url_decode(string $data)
  {
    $padded = str_pad(
      $data,
      strlen($data) % 4 === 0
        ? strlen($data)
        : strlen($data) + 4 - (strlen($data) % 4),
      "=",
    );
    return base64_decode(strtr($padded, "-_", "+/")); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
  }
}
