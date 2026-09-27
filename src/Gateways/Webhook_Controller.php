<?php
/**
 * Webhook_Controller — REST routes for gateway checkout + webhooks.
 *
 * Registers four routes per consuming slug, all under the existing
 * `wbcom-credits/v1` namespace so a single namespace covers the whole
 * SDK surface:
 *
 *  POST  /{slug}/checkout/{gateway}  Authenticated. Creates a checkout
 *                                    session and returns the redirect
 *                                    URL.
 *  POST  /{slug}/webhook/{gateway}   Public. Provider-signed; the
 *                                    signature is verified before any
 *                                    state changes.
 *  POST  /{slug}/claim/{gateway}     Authenticated. Synchronous
 *                                    redirect-return claim (1.6.0):
 *                                    verifies the session against the
 *                                    provider and credits it, so sites
 *                                    without a working webhook still
 *                                    deliver credits on return.
 *  POST  /{slug}/refund/{gateway}    Admin. Issues a provider-side
 *                                    refund. The provider's refund
 *                                    webhook is what actually adjusts
 *                                    the user's credit balance.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

use Wbcom\Credits\Credits;

/**
 * Per-slug REST controller for gateway endpoints.
 *
 * @since 1.2.0
 */
final class Webhook_Controller {

	private const NAMESPACE = 'wbcom-credits/v1';

	private string $slug;

	public function __construct( string $slug ) {
		$this->slug = $slug;
	}

	/**
	 * Register all four gateway routes for this slug.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $this->slug . '/checkout/(?P<gateway>[a-z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_checkout' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'gateway'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					// Pack mode — consumer-registered preset (preferred for
					// 1-click hosted-checkout UX). The pack maps server-side
					// to a {credits, price_cents} tuple.
					'pack_id'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					// Callback mode — client picks credit count, SDK runs
					// the consumer's registered credits_to_price_cents
					// callable to compute price. Min/max bounds enforced.
					'credits'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					// SDK 1.3.0: client-supplied price_cents is NEVER trusted.
					// The arg is intentionally absent — any value the client
					// posts is dropped by the resolver. See Pricing::resolve()
					// and docs/MIGRATION-1.3.0-pricing.md.
					'return_url' => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
					// 1.9.0: the buyer's billing (Billing::fields() keys), saved to
					// their account and snapshotted on the order.
					'billing'    => array( 'type' => 'object', 'default' => array() ),
					'coupon'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->slug . '/webhook/(?P<gateway>[a-z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true', // signature is the auth
				'args'                => array(
					'gateway' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->slug . '/claim/(?P<gateway>[a-z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'claim_checkout' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'gateway'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					// The ONLY input trusted from the browser. Payment state,
					// amount, and currency come from the gateway's own
					// provider lookup (retrieve_checkout_event) and are then
					// cross-checked against Pending_Checkouts.
					'session_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->slug . '/refund/(?P<gateway>[a-z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_refund' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'gateway'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'session_id'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'amount_cents' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	public function create_checkout( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Documented on Credits::checkout_enabled(), the one gate every purchase path asks.
		if ( ! Credits::checkout_enabled( $this->slug ) ) {
			return new \WP_Error( 'checkout_disabled', __( 'Credit purchases are not available on this site right now.', 'wbcom-credits-sdk' ), array( 'status' => 403 ) );
		}

		$gateway = $this->resolve_gateway( (string) $request->get_param( 'gateway' ) );
		if ( ! $gateway instanceof GatewayInterface ) {
			return new \WP_Error( 'unknown_gateway', 'Gateway not registered.', array( 'status' => 404 ) );
		}
		if ( ! $gateway->is_available() ) {
			return new \WP_Error( 'gateway_unavailable', 'Gateway is not configured.', array( 'status' => 409 ) );
		}

		try {
			$resolved = Pricing::resolve(
				$this->slug,
				array(
					'pack_id' => $request->get_param( 'pack_id' ),
					'credits' => $request->get_param( 'credits' ),
				)
			);
		} catch ( PricingException $e ) {
			return new \WP_Error( $e->error_code, $e->getMessage(), array( 'status' => $e->http_status ) );
		}

		// Same-host validation on return_url to block open-redirect abuse.
		$return_url = (string) ( $request->get_param( 'return_url' ) ?: '' );
		if ( '' !== $return_url ) {
			$return_host = wp_parse_url( $return_url, PHP_URL_HOST );
			$site_host   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			if ( $return_host !== $site_host ) {
				$return_url = '';
			}
		}

		// No (valid) request return_url: fall back to the page the consumer
		// registered — its own wallet/dashboard, where its buyers top up and
		// expect to land. Server-registered, so no host validation needed.
		if ( '' === $return_url ) {
			$return_url = \Wbcom\Credits\Registry::instance()->return_url_for( $this->slug );
		}

		// Billing belongs to the account: what the buyer typed is saved to
		// their billing_* meta, and the order keeps a snapshot of it.
		$user_id = get_current_user_id();
		$typed   = (array) $request->get_param( 'billing' );
		if ( ! empty( $typed ) ) {
			\Wbcom\Credits\Billing::save( $user_id, $typed, $this->slug );
		}
		$billing = \Wbcom\Credits\Billing::get( $user_id, $this->slug );
		$missing = \Wbcom\Credits\Billing::missing( $billing, $this->slug );
		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'billing_incomplete',
				__( 'Please complete your billing details.', 'wbcom-credits-sdk' ),
				array(
					'status' => 400,
					'fields' => $missing,
				)
			);
		}

		$order = Order::build( $this->slug, $resolved, (string) $request->get_param( 'coupon' ), $billing );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// A coupon with a usage limit is checked again and its use recorded
		// under a per-coupon lock (1.9.2): the free path records the order,
		// the paid path stages the checkout (a one-hour hold), before the next
		// buyer of the same code can check. Two buyers used to both pass the
		// check for the last use.
		$start = function () use ( $gateway, $order, $user_id, $return_url ) {
			return $this->start_checkout( $gateway, $order, $user_id, $return_url );
		};
		if ( '' === $order['coupon'] ) {
			return $start();
		}
		$result = Coupons::with_lock(
			$this->slug,
			$order['coupon'],
			function () use ( $start, $order ) {
				$still = Coupons::find( $this->slug, $order['coupon'] );
				return is_wp_error( $still ) ? $still : $start();
			}
		);
		if ( false === $result ) {
			return new \WP_Error( 'coupon_busy', __( 'Someone else is using this coupon right now. Please try again.', 'wbcom-credits-sdk' ), array( 'status' => 409 ) );
		}
		return $result;
	}

	/**
	 * Credit a free order, or start the gateway checkout for a paid one.
	 *
	 * @since 1.9.2
	 * @param GatewayInterface     $gateway    Gateway.
	 * @param array<string, mixed> $order      Order::build() result.
	 * @param int                  $user_id    Buyer.
	 * @param string               $return_url Where to send the buyer back.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function start_checkout( GatewayInterface $gateway, array $order, int $user_id, string $return_url ) {
		// A coupon that covers the whole price: nothing to collect, so no
		// gateway. Credited and recorded like any paid order.
		if ( $order['total'] <= 0 ) {
			$session  = 'free_' . wp_generate_password( 20, false );
			$credited = Fulfilment::credit( $this->slug, $user_id, $order['credits'], $order, 'free', $session, '', '', 0, $order['currency'] );
			if ( null === $credited ) {
				return new \WP_Error( 'topup_failed', __( 'Could not add the credits. Please try again.', 'wbcom-credits-sdk' ), array( 'status' => 500 ) );
			}
			return new \WP_REST_Response(
				array(
					'url'     => add_query_arg( 'wbcom_credits', 'success', '' !== $return_url ? $return_url : home_url( '/' ) ),
					'free'    => true,
					'credits' => $order['credits'],
				),
				200
			);
		}

		Pending_Checkouts::stage_order( $order );
		try {
			$url = $gateway->create_checkout(
				$this->slug,
				$user_id,
				$order['credits'],
				$order['total'],
				$order['currency'],
				'' !== $return_url ? $return_url : null
			);
		} catch ( \RuntimeException $e ) {
			// The provider's own words are for the site owner (debug log), not
			// the buyer, who needs to know what to do next.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( sprintf( '[wbcom-credits] %s checkout failed for %s: %s', $gateway->get_id(), $this->slug, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new \WP_Error(
				'gateway_error',
				__( 'The payment could not be started. Please try again, or contact the site owner if it keeps happening.', 'wbcom-credits-sdk' ),
				array( 'status' => 502 )
			);
		} finally {
			Pending_Checkouts::stage_order( null );
		}

		return new \WP_REST_Response( array( 'url' => $url ), 200 );
	}

	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$gateway = $this->resolve_gateway( (string) $request->get_param( 'gateway' ) );
		if ( ! $gateway instanceof GatewayInterface ) {
			return new \WP_REST_Response( array( 'error' => 'unknown_gateway' ), 404 );
		}

		$raw_body = (string) $request->get_body();
		$headers  = $this->lowercase_headers( $request->get_headers() );

		// Set the active slug so settings-readers in gateways pick the right config.
		$active_slug_filter = function () { return $this->slug; };
		add_filter( 'wbcom_credits_active_slug', $active_slug_filter, 99 );

		try {
			if ( ! $gateway->verify_signature( $raw_body, $headers ) ) {
				return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
			}

			$payload = json_decode( $raw_body, true );
			if ( ! is_array( $payload ) ) {
				return new \WP_REST_Response( array( 'error' => 'invalid_json' ), 400 );
			}

			return $gateway->handle_webhook( $this->slug, $payload );
		} finally {
			remove_filter( 'wbcom_credits_active_slug', $active_slug_filter, 99 );
		}
	}

	/**
	 * Claim a checkout on redirect-return (1.6.0).
	 *
	 * The controller owns authentication and ownership; the gateway owns
	 * provider verification and crediting. A buyer may only claim a
	 * session that Pending_Checkouts (or the Transaction_Log, once
	 * credited) records as theirs — admins may claim any.
	 */
	public function claim_checkout( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$gateway = $this->resolve_gateway( (string) $request->get_param( 'gateway' ) );
		if ( ! $gateway instanceof GatewayInterface ) {
			return new \WP_Error( 'unknown_gateway', 'Gateway not registered.', array( 'status' => 404 ) );
		}
		if ( ! $gateway->is_available() ) {
			return new \WP_Error( 'gateway_unavailable', 'Gateway is not configured.', array( 'status' => 409 ) );
		}

		$session_id = (string) $request->get_param( 'session_id' );
		$user_id    = get_current_user_id();
		$is_admin   = current_user_can( 'manage_options' );

		$expected = Pending_Checkouts::get( $this->slug, $session_id );
		if ( null !== $expected ) {
			if ( ! $is_admin && (int) $expected['user_id'] !== $user_id ) {
				return new \WP_Error( 'not_your_session', 'This checkout belongs to a different account.', array( 'status' => 403 ) );
			}
			if ( ! $gateway instanceof Abstract_Gateway ) {
				// Custom gateway outside the SDK base class — webhook-only.
				return new \WP_REST_Response( array( 'received' => true, 'pending' => true ), 202 );
			}
			return $gateway->claim_checkout( $this->slug, $session_id );
		}

		// No pending row: either already credited (a webhook or an earlier
		// claim won) or a session we never created. Answer from the
		// Transaction_Log so the buyer gets a truthful "already credited"
		// instead of an error for a payment that actually landed.
		$row = Transaction_Log::find_checkout( $this->slug, $gateway->get_id(), $session_id );
		if ( null !== $row ) {
			if ( ! $is_admin && (int) $row['user_id'] !== $user_id ) {
				return new \WP_Error( 'not_your_session', 'This checkout belongs to a different account.', array( 'status' => 403 ) );
			}
			return new \WP_REST_Response(
				array(
					'received' => true,
					'already'  => true,
					'credits'  => (int) $row['credits'],
				),
				200
			);
		}

		return new \WP_Error( 'unknown_session', 'No checkout is recorded for this session.', array( 'status' => 404 ) );
	}

	public function admin_refund( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$gateway = $this->resolve_gateway( (string) $request->get_param( 'gateway' ) );
		if ( ! $gateway instanceof GatewayInterface ) {
			return new \WP_Error( 'unknown_gateway', 'Gateway not registered.', array( 'status' => 404 ) );
		}
		$session_id   = (string) $request->get_param( 'session_id' );
		$amount_cents = $request->get_param( 'amount_cents' );
		$amount       = ( null === $amount_cents || '' === $amount_cents ) ? null : max( 1, (int) $amount_cents );

		$ok = $gateway->refund( $this->slug, $session_id, $amount );
		if ( ! $ok ) {
			return new \WP_Error( 'refund_failed', 'Refund could not be initiated.', array( 'status' => 502 ) );
		}

		return new \WP_REST_Response(
			array(
				'received'   => true,
				'session_id' => $session_id,
				'note'       => 'Refund initiated. Credit adjustment lands when the provider sends the refund webhook.',
			),
			202
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function check_logged_in(): bool {
		return is_user_logged_in();
	}

	public function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function resolve_gateway( string $gateway_id ): ?GatewayInterface {
		return Gateway_Registry::for_slug( $this->slug )->get( $gateway_id );
	}

	/**
	 * Normalize header keys to lowercase so signature checks can rely on
	 * a single shape regardless of how the REST stack hands them over.
	 *
	 * @param array<string, array<int, string>|string> $raw
	 * @return array<string, string>
	 */
	private function lowercase_headers( array $raw ): array {
		$out = array();
		foreach ( $raw as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}
		return $out;
	}
}
