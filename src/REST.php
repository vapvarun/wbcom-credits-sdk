<?php
/**
 * REST API endpoints for credit operations.
 *
 * @package Wbcom\Credits
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Registers REST routes scoped per consuming plugin slug.
 *
 * Routes:
 *   GET  /wbcom-credits/v1/{slug}/balance  — current user's balance
 *   GET  /wbcom-credits/v1/{slug}/history  — ledger entries (paginated)
 *   POST /wbcom-credits/v1/{slug}/topup    — admin manual topup
 *
 * @since 1.0.0
 */
final class REST {

	private const NAMESPACE = 'wbcom-credits/v1';

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Plugin DB prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * User type label (e.g. 'employer', 'user').
	 *
	 * @var string
	 */
	private string $user_type;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug      Plugin slug.
	 * @param string $prefix    Plugin DB prefix.
	 * @param string $user_type User type label.
	 */
	public function __construct( string $slug, string $prefix, string $user_type ) {
		$this->slug      = $slug;
		$this->prefix    = $prefix;
		$this->user_type = $user_type;
	}

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes(): void {
		$base = $this->slug;

		register_rest_route(
			self::NAMESPACE,
			'/' . $base . '/balance',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_balance' ),
				'permission_callback' => array( $this, 'check_balance_permission' ),
				'args'                => array(
					'user_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $base . '/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_history' ),
				'permission_callback' => array( $this, 'check_balance_permission' ),
				'args'                => array(
					'user_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'limit'   => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
					'offset'  => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $base . '/topup',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_topup' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'user_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'amount'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'intval',
					),
					'note'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET balance — returns current user's or specified user's balance.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_balance( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = $this->resolve_user_id( $request );

		return new \WP_REST_Response(
			array(
				'user_id' => $user_id,
				'balance' => Credits::get_balance( $this->slug, $user_id ),
				'enabled' => Credits::is_enabled( $this->slug ),
			)
		);
	}

	/**
	 * GET history — returns ledger entries for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_history( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = $this->resolve_user_id( $request );
		$limit   = $request->get_param( 'limit' ) ?? 50;
		$offset  = $request->get_param( 'offset' ) ?? 0;

		return new \WP_REST_Response(
			array(
				'user_id' => $user_id,
				'balance' => Credits::get_balance( $this->slug, $user_id ),
				'entries' => Credits::get_ledger( $this->slug, $user_id, (int) $limit, (int) $offset ),
			)
		);
	}

	/**
	 * POST topup — admin manual credit adjustment.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function admin_topup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = (int) $request->get_param( 'user_id' );
		$amount  = (int) $request->get_param( 'amount' );
		$note    = (string) $request->get_param( 'note' );

		if ( 0 === $user_id || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'wbcom_credits_invalid_user', __( 'Invalid user ID.', 'wbcom-credits-sdk' ), array( 'status' => 400 ) );
		}

		if ( 0 === $amount ) {
			return new \WP_Error( 'wbcom_credits_zero_amount', __( 'Amount cannot be zero.', 'wbcom-credits-sdk' ), array( 'status' => 400 ) );
		}

		$result = Credits::adjust( $this->slug, $user_id, $amount, $note ?: 'Admin REST adjustment' );

		if ( false === $result ) {
			return new \WP_Error( 'wbcom_credits_db_error', __( 'Failed to record credit adjustment.', 'wbcom-credits-sdk' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'user_id'     => $user_id,
				'adjusted'    => $amount,
				'new_balance' => Credits::get_balance( $this->slug, $user_id ),
			)
		);
	}

	/**
	 * Permission: current user can view own balance, or admin can view any.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function check_balance_permission( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$requested_id = $request->get_param( 'user_id' );

		// No user_id param = own balance.
		if ( empty( $requested_id ) ) {
			return true;
		}

		// Admins can view any user's balance.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Users can only view their own balance.
		return (int) $requested_id === get_current_user_id();
	}

	/**
	 * Permission: admin only.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Resolve user ID from request — defaults to current user.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return int User ID.
	 */
	private function resolve_user_id( \WP_REST_Request $request ): int {
		$user_id = $request->get_param( 'user_id' );
		return $user_id ? (int) $user_id : get_current_user_id();
	}
}
