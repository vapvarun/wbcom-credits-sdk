<?php
/**
 * PayPal gateway — direct credit purchases via PayPal Orders API v2.
 *
 * Uses PayPal's hosted approval flow (no card data on-site, no PCI
 * exposure) and v2 Orders for one-time payments. Subscription billing
 * lands in v1.3.
 *
 * Inherits all webhook orchestration (idempotency, amount cross-check,
 * top-up, refund accounting, Transaction_Log writes) from
 * {@see Abstract_Gateway}. Only the small surface that PayPal defines
 * differently lives here.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal Orders v2 gateway.
 *
 * @since 1.2.0
 */
final class PayPal extends Abstract_Gateway {

	public const ID = 'paypal';

	private const API_LIVE    = 'https://api-m.paypal.com';
	private const API_SANDBOX = 'https://api-m.sandbox.paypal.com';

	public function get_id(): string {
		return self::ID;
	}

	public function get_label(): string {
		return __( 'PayPal', 'wbcom-credits-sdk' );
	}

	public function is_available(): bool {
		$settings = $this->get_settings_for_slug( $this->active_slug() );
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		return '' !== (string) ( $settings['client_id'] ?? '' )
			&& '' !== (string) ( $settings['client_secret'] ?? '' );
	}

	public function get_settings_fields(): array {
		return array(
			array( 'key' => 'enabled',       'type' => 'bool',     'label' => __( 'Enable PayPal', 'wbcom-credits-sdk' ) ),
			array(
				'key'     => 'mode',
				'type'    => 'select',
				'label'   => __( 'Mode', 'wbcom-credits-sdk' ),
				'options' => array(
					'sandbox' => __( 'Sandbox', 'wbcom-credits-sdk' ),
					'live'    => __( 'Live', 'wbcom-credits-sdk' ),
				),
			),
			array( 'key' => 'client_id',     'type' => 'text',     'label' => __( 'Client ID', 'wbcom-credits-sdk' ) ),
			array( 'key' => 'client_secret', 'type' => 'password', 'label' => __( 'Client secret', 'wbcom-credits-sdk' ) ),
			array( 'key' => 'webhook_id',    'type' => 'text',     'label' => __( 'Webhook ID', 'wbcom-credits-sdk' ) ),
		);
	}

	/**
	 * Create a PayPal Order (v2) and return the approval URL.
	 *
	 * @throws \RuntimeException When PayPal rejects the request or no approval link is returned.
	 */
	public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD' ): string {
		if ( $user_id <= 0 || $credits <= 0 || $price_cents <= 0 ) {
			throw new \RuntimeException( 'Invalid checkout parameters.' );
		}
		$settings = $this->get_settings_for_slug( $slug );
		$base     = self::api_base( $settings );
		$token    = self::access_token( $settings, $base );
		if ( '' === $token ) {
			throw new \RuntimeException( 'PayPal is not configured.' );
		}

		$amount_str = number_format( $price_cents / 100, 2, '.', '' );

		$body = array(
			'intent'              => 'CAPTURE',
			'purchase_units'      => array(
				array(
					'reference_id' => $slug . ':' . $user_id,
					'description'  => sprintf(
						/* translators: %d: credit count */
						__( '%d credits', 'wbcom-credits-sdk' ),
						$credits
					),
					'custom_id'    => wp_json_encode(
						array(
							'slug'    => $slug,
							'user_id' => $user_id,
							'credits' => $credits,
						)
					),
					'amount'       => array(
						'currency_code' => strtoupper( $currency ),
						'value'         => $amount_str,
					),
				),
			),
			'application_context' => array(
				'return_url' => add_query_arg( 'wbcom_credits', 'success', home_url( '/' ) ),
				'cancel_url' => add_query_arg( 'wbcom_credits', 'cancel', home_url( '/' ) ),
			),
		);

		$response = wp_remote_post(
			$base . '/v2/checkout/orders',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'PayPal order request failed: ' . $response->get_error_message() );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['id'] ) || empty( $decoded['links'] ) ) {
			throw new \RuntimeException( 'PayPal returned an invalid order response.' );
		}

		$approve_url = '';
		foreach ( (array) $decoded['links'] as $link ) {
			if ( is_array( $link ) && 'approve' === ( $link['rel'] ?? '' ) ) {
				$approve_url = (string) ( $link['href'] ?? '' );
				break;
			}
		}
		if ( '' === $approve_url ) {
			throw new \RuntimeException( 'PayPal order missing approve link.' );
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

		return $approve_url;
	}

	public function verify_signature( string $raw_body, array $headers ): bool {
		$settings   = $this->get_settings_for_slug( $this->active_slug() );
		$webhook_id = (string) ( $settings['webhook_id'] ?? '' );
		$client_id  = (string) ( $settings['client_id'] ?? '' );
		$secret     = (string) ( $settings['client_secret'] ?? '' );
		$payload    = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return false;
		}
		return Signature_Verifier::verify_paypal(
			$headers,
			$payload,
			$webhook_id,
			$client_id,
			$secret,
			self::api_base( $settings )
		);
	}

	public function normalize_event( array $payload ): ?Gateway_Event {
		$event_id = (string) ( $payload['id'] ?? '' );
		$type     = (string) ( $payload['event_type'] ?? '' );
		$resource = is_array( $payload['resource'] ?? null ) ? $payload['resource'] : array();

		if ( 'PAYMENT.CAPTURE.COMPLETED' === $type ) {
			$order_id = (string) (
				$resource['supplementary_data']['related_ids']['order_id']
				?? $resource['custom_id']
				?? ''
			);
			if ( '' === $order_id ) {
				return null;
			}
			$amount_str = (string) ( $resource['amount']['value'] ?? '0' );
			return new Gateway_Event(
				type: Gateway_Event::TYPE_CHECKOUT_COMPLETED,
				event_id: $event_id,
				session_id: $order_id,
				amount_cents: (int) round( ( (float) $amount_str ) * 100 ),
				currency: strtoupper( (string) ( $resource['amount']['currency_code'] ?? '' ) ),
				raw: $payload
			);
		}

		if ( 'PAYMENT.CAPTURE.REFUNDED' === $type ) {
			// In a refund webhook, supplementary_data.related_ids.captured_payment
			// (PayPal calls it `up_id` in some payloads) ties back to the original
			// capture; the parent ORDER id is what we stored in Pending_Checkouts.
			$order_id = (string) (
				$resource['supplementary_data']['related_ids']['order_id']
				?? $resource['supplementary_data']['related_ids']['parent_payment']
				?? ''
			);
			if ( '' === $order_id ) {
				return null;
			}
			$amount_str = (string) ( $resource['amount']['value'] ?? '0' );
			return new Gateway_Event(
				type: Gateway_Event::TYPE_REFUND,
				event_id: $event_id,
				session_id: $order_id,
				amount_cents: (int) round( ( (float) $amount_str ) * 100 ),
				currency: strtoupper( (string) ( $resource['amount']['currency_code'] ?? '' ) ),
				raw: $payload
			);
		}

		return null;
	}

	/**
	 * Issue a refund via PayPal's Captures Refund endpoint.
	 *
	 * Looks up the capture id from the order, then POSTs to
	 * `/v2/payments/captures/{capture_id}/refund`. The refund webhook
	 * (`PAYMENT.CAPTURE.REFUNDED`) is what actually debits credits.
	 */
	public function refund( string $slug, string $session_id, ?int $amount_cents = null ): bool {
		$settings = $this->get_settings_for_slug( $slug );
		$base     = self::api_base( $settings );
		$token    = self::access_token( $settings, $base );
		if ( '' === $token || '' === $session_id ) {
			return false;
		}

		// Resolve capture id from the order.
		$lookup = wp_remote_get(
			$base . '/v2/checkout/orders/' . rawurlencode( $session_id ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $lookup ) ) {
			return false;
		}
		$order      = json_decode( (string) wp_remote_retrieve_body( $lookup ), true );
		$capture_id = '';
		if ( is_array( $order ) ) {
			foreach ( (array) ( $order['purchase_units'] ?? array() ) as $unit ) {
				foreach ( (array) ( $unit['payments']['captures'] ?? array() ) as $cap ) {
					$capture_id = (string) ( $cap['id'] ?? '' );
					if ( '' !== $capture_id ) {
						break 2;
					}
				}
			}
		}
		if ( '' === $capture_id ) {
			return false;
		}

		$body = array();
		if ( null !== $amount_cents && $amount_cents > 0 ) {
			$body['amount'] = array(
				// Currency must match capture; PayPal will reject mismatches.
				'currency_code' => strtoupper( (string) ( $unit['amount']['currency_code'] ?? 'USD' ) ),
				'value'         => number_format( $amount_cents / 100, 2, '.', '' ),
			);
		}

		$response = wp_remote_post(
			$base . '/v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) && 'COMPLETED' === ( $decoded['status'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// PayPal API plumbing
	// -------------------------------------------------------------------------

	private static function api_base( array $settings ): string {
		return ( ( $settings['mode'] ?? 'sandbox' ) === 'live' ) ? self::API_LIVE : self::API_SANDBOX;
	}

	/**
	 * Mint (or reuse) an OAuth2 access token. Cached for 9 minutes per
	 * (mode, client_id) pair so concurrent sites don't share tokens.
	 */
	private static function access_token( array $settings, string $base ): string {
		$client_id = (string) ( $settings['client_id'] ?? '' );
		$secret    = (string) ( $settings['client_secret'] ?? '' );
		if ( '' === $client_id || '' === $secret ) {
			return '';
		}
		$cache_key = 'wbcom_credits_paypal_token_' . md5( $base . '|' . $client_id );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_post(
			$base . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
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
		$token = (string) $decoded['access_token'];
		set_transient( $cache_key, $token, 9 * MINUTE_IN_SECONDS );
		return $token;
	}
}
