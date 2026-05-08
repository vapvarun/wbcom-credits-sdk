<?php
/**
 * Stripe gateway — direct credit purchases via Stripe Checkout.
 *
 * Uses Stripe Checkout (hosted by Stripe) so cardholder data never
 * touches the site, keeping consuming plugins out of PCI scope. Only
 * the small subset of Stripe API calls we need (`POST /v1/checkout
 * /sessions`, `POST /v1/refunds`) is implemented via wp_remote_post,
 * so consuming plugins do not bundle the official Stripe PHP SDK
 * (~1 MB).
 *
 * Inherits all webhook orchestration (idempotency, amount cross-check,
 * top-up, refund accounting, Transaction_Log writes) from
 * {@see Abstract_Gateway}. Only the small surface that Stripe defines
 * differently lives here.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Checkout gateway.
 *
 * @since 1.2.0
 */
final class Stripe extends Abstract_Gateway {

	public const ID = 'stripe';

	public function get_id(): string {
		return self::ID;
	}

	public function get_label(): string {
		return __( 'Stripe', 'wbcom-credits-sdk' );
	}

	public function is_available(): bool {
		$settings = $this->get_settings_for_slug( $this->active_slug() );
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		return '' !== $this->secret_key( $settings );
	}

	public function get_settings_fields(): array {
		return array(
			array( 'key' => 'enabled',         'type' => 'bool',     'label' => __( 'Enable Stripe', 'wbcom-credits-sdk' ) ),
			array(
				'key'     => 'mode',
				'type'    => 'select',
				'label'   => __( 'Mode', 'wbcom-credits-sdk' ),
				'options' => array(
					'test' => __( 'Test', 'wbcom-credits-sdk' ),
					'live' => __( 'Live', 'wbcom-credits-sdk' ),
				),
			),
			array( 'key' => 'publishable_key', 'type' => 'text',     'label' => __( 'Publishable key (active mode)', 'wbcom-credits-sdk' ) ),
			array( 'key' => 'secret_key',      'type' => 'password', 'label' => __( 'Secret key (active mode)', 'wbcom-credits-sdk' ) ),
			array( 'key' => 'webhook_secret',  'type' => 'password', 'label' => __( 'Webhook signing secret', 'wbcom-credits-sdk' ) ),
			array( 'key' => 'success_url',     'type' => 'url',      'label' => __( 'Post-purchase redirect', 'wbcom-credits-sdk' ) ),
			array( 'key' => 'cancel_url',      'type' => 'url',      'label' => __( 'Cancel-redirect URL', 'wbcom-credits-sdk' ) ),
		);
	}

	/**
	 * Build a hosted Stripe Checkout session and return the URL.
	 *
	 * @throws \RuntimeException When parameters are invalid or Stripe rejects the request.
	 */
	public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD', ?string $return_url = null ): string {
		if ( $user_id <= 0 || $credits <= 0 || $price_cents <= 0 ) {
			throw new \RuntimeException( 'Invalid checkout parameters.' );
		}
		$settings = $this->get_settings_for_slug( $slug );
		$secret   = $this->secret_key( $settings );
		if ( '' === $secret ) {
			throw new \RuntimeException( 'Stripe is not configured.' );
		}

		// Per-checkout return_url override — when the consuming block knows
		// the page it was rendered on (e.g. dashboard?tab=credits), it passes
		// that here so users land back on the same page after Stripe instead
		// of the global success_url. Falls back to settings, then home_url.
		$return_url  = ( null !== $return_url && '' !== $return_url ) ? $return_url : '';
		$success_url = '' !== $return_url
			? add_query_arg( array( 'wbcom_credits' => 'success', 'gateway' => self::ID, 'credits' => $credits ), $return_url )
			: (string) ( $settings['success_url'] ?? '' );
		$cancel_url  = '' !== $return_url
			? add_query_arg( array( 'wbcom_credits' => 'cancel', 'gateway' => self::ID ), $return_url )
			: (string) ( $settings['cancel_url'] ?? '' );
		if ( '' === $success_url ) {
			$success_url = home_url( '/?wbcom_credits=success' );
		}
		if ( '' === $cancel_url ) {
			$cancel_url = home_url( '/?wbcom_credits=cancel' );
		}

		$body = array(
			'mode'                                          => 'payment',
			'success_url'                                   => add_query_arg( 'session_id', '{CHECKOUT_SESSION_ID}', $success_url ),
			'cancel_url'                                    => $cancel_url,
			'client_reference_id'                           => $slug . ':' . $user_id,
			'metadata[wbcom_slug]'                          => $slug,
			'metadata[wbcom_user_id]'                       => (string) $user_id,
			'metadata[wbcom_credits]'                       => (string) $credits,
			'line_items[0][quantity]'                       => '1',
			'line_items[0][price_data][currency]'           => strtolower( $currency ),
			'line_items[0][price_data][unit_amount]'        => (string) $price_cents,
			'line_items[0][price_data][product_data][name]' => sprintf(
				/* translators: %d: credit count */
				__( '%d credits', 'wbcom-credits-sdk' ),
				$credits
			),
		);

		$response = wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $body, '', '&' ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Stripe checkout request failed: ' . $response->get_error_message() );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['id'] ) || empty( $decoded['url'] ) ) {
			throw new \RuntimeException( 'Stripe returned an invalid checkout response.' );
		}

		Pending_Checkouts::put(
			$slug,
			(string) $decoded['id'],
			array(
				'gateway'     => self::ID,
				'user_id'     => $user_id,
				'credits'     => $credits,
				'price_cents' => $price_cents,
				'currency'    => $currency,
			)
		);

		return (string) $decoded['url'];
	}

	public function verify_signature( string $raw_body, array $headers ): bool {
		$settings = $this->get_settings_for_slug( $this->active_slug() );
		$secret   = (string) ( $settings['webhook_secret'] ?? '' );
		$header   = (string) ( $headers['stripe-signature'] ?? '' );

		return Signature_Verifier::verify_stripe( $raw_body, $header, $secret );
	}

	public function normalize_event( array $payload ): ?Gateway_Event {
		$event_id = (string) ( $payload['id'] ?? '' );
		$type     = (string) ( $payload['type'] ?? '' );

		if ( 'checkout.session.completed' === $type ) {
			$session = $payload['data']['object'] ?? array();
			if ( ! is_array( $session ) || empty( $session['id'] ) ) {
				return null;
			}
			if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
				return null;
			}
			return new Gateway_Event(
				type: Gateway_Event::TYPE_CHECKOUT_COMPLETED,
				event_id: $event_id,
				session_id: (string) $session['id'],
				amount_cents: (int) ( $session['amount_total'] ?? 0 ),
				currency: strtoupper( (string) ( $session['currency'] ?? '' ) ),
				raw: $payload
			);
		}

		if ( 'charge.refunded' === $type ) {
			$charge     = $payload['data']['object'] ?? array();
			$session_id = (string) ( $charge['payment_intent'] ?? '' );
			// Stripe charge events list payment_intent, but we record sessions.
			// Resolve the session id from the previous attribute if Stripe
			// supplies it, otherwise rely on the latest_charge → session
			// linkage stored in metadata when the session was created.
			if ( '' === $session_id && isset( $charge['metadata']['wbcom_session'] ) ) {
				$session_id = (string) $charge['metadata']['wbcom_session'];
			}
			$refund_amount = (int) ( $charge['amount_refunded'] ?? 0 );
			if ( '' === $session_id || $refund_amount <= 0 ) {
				return null;
			}
			return new Gateway_Event(
				type: Gateway_Event::TYPE_REFUND,
				event_id: $event_id,
				session_id: $session_id,
				amount_cents: $refund_amount,
				currency: strtoupper( (string) ( $charge['currency'] ?? '' ) ),
				raw: $payload
			);
		}

		return null;
	}

	/**
	 * Issue a refund through the Stripe Refunds API.
	 *
	 * Stripe refunds the underlying PaymentIntent, not the Checkout
	 * Session. We resolve `payment_intent` by retrieving the session
	 * first, then call /v1/refunds. The refund webhook (`charge.refunded`)
	 * is what actually adjusts the user's credits — this method only
	 * triggers the refund.
	 */
	public function refund( string $slug, string $session_id, ?int $amount_cents = null ): bool {
		$settings = $this->get_settings_for_slug( $slug );
		$secret   = $this->secret_key( $settings );
		if ( '' === $secret || '' === $session_id ) {
			return false;
		}

		// Resolve payment_intent from the session.
		$lookup = wp_remote_get(
			'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( $session_id ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $lookup ) ) {
			return false;
		}
		$session         = json_decode( (string) wp_remote_retrieve_body( $lookup ), true );
		$payment_intent  = is_array( $session ) ? (string) ( $session['payment_intent'] ?? '' ) : '';
		if ( '' === $payment_intent ) {
			return false;
		}

		$body = array( 'payment_intent' => $payment_intent );
		if ( null !== $amount_cents && $amount_cents > 0 ) {
			$body['amount'] = (string) $amount_cents;
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/refunds',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $body, '', '&' ),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded )
			&& 'succeeded' === ( $decoded['status'] ?? '' );
	}

	/**
	 * Resolve the secret key for the active mode.
	 *
	 * Sites can store mode-specific keys (`secret_key_live` / `secret_key_test`)
	 * or a single `secret_key` for the active mode — we accept either so
	 * admins choosing the simpler "one key at a time" UX still work.
	 */
	private function secret_key( array $settings ): string {
		$mode     = ( ( $settings['mode'] ?? 'test' ) === 'live' ) ? 'live' : 'test';
		$specific = (string) ( $settings[ 'secret_key_' . $mode ] ?? '' );
		if ( '' !== $specific ) {
			return $specific;
		}
		return (string) ( $settings['secret_key'] ?? '' );
	}
}
