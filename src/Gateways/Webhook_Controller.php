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
					'gateway'     => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'credits'     => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'price_cents' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'currency'    => array( 'type' => 'string', 'default' => 'USD', 'sanitize_callback' => 'sanitize_text_field' ),
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
		$gateway = $this->resolve_gateway( (string) $request->get_param( 'gateway' ) );
		if ( ! $gateway instanceof GatewayInterface ) {
			return new \WP_Error( 'unknown_gateway', 'Gateway not registered.', array( 'status' => 404 ) );
		}
		if ( ! $gateway->is_available() ) {
			return new \WP_Error( 'gateway_unavailable', 'Gateway is not configured.', array( 'status' => 409 ) );
		}

		try {
			$url = $gateway->create_checkout(
				$this->slug,
				get_current_user_id(),
				(int) $request->get_param( 'credits' ),
				(int) $request->get_param( 'price_cents' ),
				strtoupper( (string) ( $request->get_param( 'currency' ) ?: 'USD' ) )
			);
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'gateway_error', $e->getMessage(), array( 'status' => 502 ) );
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
