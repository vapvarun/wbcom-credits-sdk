<?php
/**
 * Signature verification — Stripe (HMAC) + PayPal (verify-API) helpers.
 *
 * Stripe signs every webhook with HMAC-SHA256 over `t=<ts>.<raw_body>`,
 * documented at https://stripe.com/docs/webhooks/signatures. We hand-roll
 * the verification so consuming plugins don't need to bundle the Stripe
 * PHP SDK (~1 MB). The implementation is a small, scope-isolated helper
 * that other gateways can reuse for raw HMAC checks.
 *
 * PayPal does not publish a verifiable HMAC scheme — instead, every
 * webhook must be re-submitted to PayPal's `notifications/verify-webhook
 * -signature` endpoint along with the original payload and headers. This
 * helper handles the OAuth2 token dance and the verify call.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless signature verifiers for supported gateways.
 *
 * @since 1.2.0
 */
final class Signature_Verifier {

	/**
	 * Tolerance for Stripe timestamps, in seconds.
	 *
	 * @var int
	 */
	private const STRIPE_TOLERANCE = 300;

	// -------------------------------------------------------------------------
	// Stripe
	// -------------------------------------------------------------------------

	/**
	 * Verify a Stripe webhook signature.
	 *
	 * The header arrives as e.g. `t=1614000000,v1=abc123,v0=...`. We
	 * accept v1 (HMAC-SHA256). The timestamp must be within
	 * STRIPE_TOLERANCE of the current time to defeat replay attacks.
	 *
	 * @param string $raw_body       Raw request body (php://input).
	 * @param string $signature_hdr  Value of the `Stripe-Signature` header.
	 * @param string $webhook_secret Site-configured signing secret (whsec_...).
	 * @param int    $now            Current timestamp; injected for tests.
	 * @return bool True if the signature is valid and recent.
	 */
	public static function verify_stripe(
		string $raw_body,
		string $signature_hdr,
		string $webhook_secret,
		int $now = 0
	): bool {
		if ( '' === $raw_body || '' === $signature_hdr || '' === $webhook_secret ) {
			return false;
		}
		$now = $now > 0 ? $now : time();

		$parts = self::parse_stripe_header( $signature_hdr );
		if ( null === $parts ) {
			return false;
		}

		// Replay window guard.
		if ( abs( $now - $parts['t'] ) > self::STRIPE_TOLERANCE ) {
			return false;
		}

		$signed_payload = $parts['t'] . '.' . $raw_body;
		$expected       = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		// Constant-time compare across each `v1` value Stripe sent.
		foreach ( $parts['v1'] as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a `Stripe-Signature` header into its components.
	 *
	 * @param string $header Raw header value.
	 * @return array{t:int, v1:array<int,string>}|null Null if malformed.
	 */
	private static function parse_stripe_header( string $header ): ?array {
		$timestamp = 0;
		$v1        = array();
		foreach ( explode( ',', $header ) as $segment ) {
			$pair = explode( '=', trim( $segment ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			[ $key, $value ] = $pair;
			$key             = strtolower( trim( $key ) );
			$value           = trim( $value );
			if ( 't' === $key && ctype_digit( $value ) ) {
				$timestamp = (int) $value;
			} elseif ( 'v1' === $key && '' !== $value ) {
				$v1[] = $value;
			}
		}
		if ( $timestamp <= 0 || empty( $v1 ) ) {
			return null;
		}
		return array(
			't'  => $timestamp,
			'v1' => $v1,
		);
	}

	// -------------------------------------------------------------------------
	// PayPal
	// -------------------------------------------------------------------------

	/**
	 * Verify a PayPal webhook by calling PayPal's verify-signature endpoint.
	 *
	 * Pre-validates fields locally (cheap) before incurring the API call.
	 * Returns false on any HTTP / JSON failure so a misconfigured gateway
	 * can never accidentally accept an unverified payload.
	 *
	 * @param array  $headers       Request headers (lowercased keys).
	 * @param array  $payload       Decoded JSON payload from PayPal.
	 * @param string $webhook_id    Site-configured webhook ID.
	 * @param string $client_id     PayPal client ID (used for OAuth2).
	 * @param string $client_secret PayPal client secret.
	 * @param string $api_base      'https://api-m.sandbox.paypal.com' or live.
	 * @return bool
	 */
	public static function verify_paypal(
		array $headers,
		array $payload,
		string $webhook_id,
		string $client_id,
		string $client_secret,
		string $api_base
	): bool {
		$required = array(
			'paypal-auth-algo',
			'paypal-cert-url',
			'paypal-transmission-id',
			'paypal-transmission-sig',
			'paypal-transmission-time',
		);
		foreach ( $required as $h ) {
			if ( empty( $headers[ $h ] ) ) {
				return false;
			}
		}
		if ( empty( $payload ) || empty( $webhook_id ) || empty( $client_id ) || empty( $client_secret ) || empty( $api_base ) ) {
			return false;
		}

		$token = self::paypal_access_token( $client_id, $client_secret, $api_base );
		if ( '' === $token ) {
			return false;
		}

		$body = array(
			'auth_algo'         => (string) $headers['paypal-auth-algo'],
			'cert_url'          => (string) $headers['paypal-cert-url'],
			'transmission_id'   => (string) $headers['paypal-transmission-id'],
			'transmission_sig'  => (string) $headers['paypal-transmission-sig'],
			'transmission_time' => (string) $headers['paypal-transmission-time'],
			'webhook_id'        => $webhook_id,
			'webhook_event'     => $payload,
		);

		$response = wp_remote_post(
			rtrim( $api_base, '/' ) . '/v1/notifications/verify-webhook-signature',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) && 'SUCCESS' === ( $decoded['verification_status'] ?? null );
	}

	/**
	 * Acquire a short-lived OAuth2 access token from PayPal.
	 *
	 * @param string $client_id     PayPal client ID.
	 * @param string $client_secret PayPal client secret.
	 * @param string $api_base      Sandbox or live base URL.
	 * @return string Bearer token, or empty string on failure.
	 */
	private static function paypal_access_token( string $client_id, string $client_secret, string $api_base ): string {
		$response = wp_remote_post(
			rtrim( $api_base, '/' ) . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => 'grant_type=client_credentials',
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			return '';
		}
		return (string) $decoded['access_token'];
	}
}
