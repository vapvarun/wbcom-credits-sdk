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
			array( 'key' => 'client_id',     'type' => 'text',     'label' => __( 'Client ID', 'wbcom-credits-sdk' ), 'required' => true ),
			array( 'key' => 'client_secret', 'type' => 'password', 'label' => __( 'Client secret', 'wbcom-credits-sdk' ), 'required' => true ),
			// Required: PayPal has no synchronous claim path, so the webhook
			// is the only crediting mechanism.
			array( 'key' => 'webhook_id',    'type' => 'text',     'label' => __( 'Webhook ID', 'wbcom-credits-sdk' ), 'required' => true ),
		);
	}

	/**
	 * Create a PayPal Order (v2) and return the approval URL.
	 *
	 * @throws \RuntimeException When PayPal rejects the request or no approval link is returned.
	 */
	public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD', ?string $return_url = null ): string {
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

		// Per-checkout return_url override — see Stripe.php for the same
		// logic, including why the gateway params are appended to whichever
		// base wins rather than only the return_url branch.
		$return_url   = ( null !== $return_url && '' !== $return_url ) ? $return_url : '';
		$success_base = '' !== $return_url ? $return_url : (string) ( $settings['success_url'] ?? '' );
		$cancel_base  = '' !== $return_url ? $return_url : (string) ( $settings['cancel_url'] ?? '' );
		if ( '' === $success_base ) {
			$success_base = home_url( '/' );
		}
		if ( '' === $cancel_base ) {
			$cancel_base = home_url( '/' );
		}
		$paypal_success = add_query_arg(
			array(
				'wbcom_credits' => 'success',
				'gateway'       => self::ID,
				'credits'       => $credits,
			),
			$success_base
		);
		$paypal_cancel  = add_query_arg(
			array(
				'wbcom_credits' => 'cancel',
				'gateway'       => self::ID,
			),
			$cancel_base
		);

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
				'return_url' => $paypal_success,
				'cancel_url' => $paypal_cancel,
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

		$order_id = (string) $decoded['id'];

		// Stamp the order id into custom_id so a later refund webhook carries
		// it straight back (preferred resolution path in normalize_event()).
		// custom_id cannot be set to the order id at create time — the id does
		// not exist yet — so we PATCH it now that PayPal has minted it. This is
		// best-effort: PayPal copies custom_id onto the capture + refund, but a
		// PATCH failure is non-fatal because the refund path also falls back to
		// supplementary_data.related_ids and a Transaction_Log capture-id
		// lookup. We never block checkout on it.
		self::stamp_custom_id( $base, $token, $order_id, $slug, $user_id, $credits );

		Pending_Checkouts::put(
			$slug,
			$order_id,
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

	/**
	 * PATCH a freshly-created order's purchase-unit custom_id to embed the
	 * order id (our checkout session id) alongside slug/user/credits.
	 *
	 * PayPal copies custom_id onto the capture and onto the refund resource,
	 * so embedding the order id here lets the refund webhook resolve its parent
	 * checkout directly — the PayPal analogue of Stripe stamping the Checkout
	 * Session id onto payment_intent_data.metadata. Best-effort by design:
	 * returns silently on any failure since refund resolution has fallbacks.
	 *
	 * @param string $base     PayPal API base URL.
	 * @param string $token    OAuth2 bearer token.
	 * @param string $order_id The PayPal order id (our session id).
	 * @param string $slug     Consuming plugin slug.
	 * @param int    $user_id  WordPress user id.
	 * @param int    $credits  Credits being purchased.
	 * @return void
	 */
	private static function stamp_custom_id( string $base, string $token, string $order_id, string $slug, int $user_id, int $credits ): void {
		$custom_id = wp_json_encode(
			array(
				'slug'    => $slug,
				'user_id' => $user_id,
				'credits' => $credits,
				'session' => $order_id,
			)
		);
		if ( false === $custom_id ) {
			return;
		}

		$patch = array(
			array(
				'op'    => 'replace',
				'path'  => "/purchase_units/@reference_id=='" . $slug . ':' . $user_id . "'/custom_id",
				'value' => $custom_id,
			),
		);

		wp_remote_request(
			$base . '/v2/checkout/orders/' . rawurlencode( $order_id ),
			array(
				'method'  => 'PATCH',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $patch ),
				'timeout' => 15,
			)
		);
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
				raw: $payload,
				// Record the CAPTURE id (resource.id on the capture event) so a
				// later refund webhook that does NOT carry the parent order id
				// can still resolve this checkout via
				// Transaction_Log::find_checkout_by_payment_intent(). This is the
				// PayPal analogue of Stripe's payment_intent linkage.
				provider_ref: (string) ( $resource['id'] ?? '' )
			);
		}

		if ( 'PAYMENT.CAPTURE.REFUNDED' === $type ) {
			// The parent checkout row is keyed by the PayPal ORDER id we stored
			// in Pending_Checkouts / Transaction_Log at checkout. A refund
			// resource does not reliably carry that order id, so resolve in
			// priority order, mirroring Stripe's prefer-stamp-then-fallback:
			//
			//   1. The order id stamped into custom_id at checkout creation
			//      (we put a JSON blob with a `session` key on the purchase
			//      unit's custom_id; PayPal copies custom_id onto the capture
			//      and the refund resource, so it travels back to us).
			//   2. supplementary_data.related_ids.order_id when PayPal includes
			//      it directly on the refund.
			//   3. Transaction_Log lookup keyed on the original CAPTURE id
			//      (related_ids.captured_payment / up_id) which we recorded as
			//      provider_ref on the checkout — the equivalent of Stripe's
			//      payment_intent fallback.
			$order_id = self::session_from_custom_id( $resource['custom_id'] ?? '' );

			if ( '' === $order_id ) {
				$order_id = (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' );
			}

			if ( '' === $order_id ) {
				$capture_id = (string) (
					$resource['supplementary_data']['related_ids']['captured_payment']
					?? $resource['supplementary_data']['related_ids']['up_id']
					?? ''
				);
				if ( '' !== $capture_id ) {
					$parent = Transaction_Log::find_checkout_by_payment_intent(
						$this->active_slug(),
						self::ID,
						$capture_id
					);
					if ( null !== $parent ) {
						$order_id = (string) ( $parent['session_id'] ?? '' );
					}
				}
			}

			if ( '' === $order_id ) {
				return null;
			}
			$amount_str    = (string) ( $resource['amount']['value'] ?? '0' );
			$refund_amount = (int) round( ( (float) $amount_str ) * 100 );
			// Mirror Stripe: a zero/negative refund amount is a non-event. The
			// Abstract_Gateway refund path additionally clamps to the captured
			// total, so we only have to guard the trivially-empty case here.
			if ( $refund_amount <= 0 ) {
				return null;
			}
			return new Gateway_Event(
				type: Gateway_Event::TYPE_REFUND,
				event_id: $event_id,
				session_id: $order_id,
				amount_cents: $refund_amount,
				currency: strtoupper( (string) ( $resource['amount']['currency_code'] ?? '' ) ),
				raw: $payload
			);
		}

		return null;
	}

	/**
	 * Extract the stamped checkout session id (PayPal order id) from a
	 * purchase-unit/capture `custom_id`.
	 *
	 * At checkout we JSON-encode `{ slug, user_id, credits, session }` into
	 * custom_id. PayPal copies that string onto the capture and onto the
	 * refund resource, so the refund webhook carries our order id back without
	 * a Transaction_Log round-trip. Tolerates a non-JSON or legacy custom_id
	 * (returns '' so the caller falls through to the related_ids / capture
	 * fallbacks).
	 *
	 * @param mixed $custom_id Raw custom_id value from the PayPal resource.
	 * @return string Stamped order id, or '' when absent/unparseable.
	 */
	private static function session_from_custom_id( $custom_id ): string {
		if ( ! is_string( $custom_id ) || '' === $custom_id ) {
			return '';
		}
		$decoded = json_decode( $custom_id, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		return (string) ( $decoded['session'] ?? '' );
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
