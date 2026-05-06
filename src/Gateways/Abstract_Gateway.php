<?php
/**
 * Abstract_Gateway — shared orchestration for every payment gateway.
 *
 * Implements the parts of {@see GatewayInterface} that are identical
 * across providers: webhook idempotency, amount/currency cross-check,
 * top-up dispatch, refund accounting, Transaction_Log writes, and
 * settings access. Each concrete gateway only implements the small
 * provider-specific surface (create_checkout, verify_signature,
 * normalize_event, refund) and inherits the rest.
 *
 * Adding a new gateway should be ~150 lines, not ~400.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

use Wbcom\Credits\Credits;

/**
 * Shared base for every gateway implementation.
 *
 * @since 1.2.0
 */
abstract class Abstract_Gateway implements GatewayInterface {

	/**
	 * Final webhook entry point — orchestrates the full lifecycle.
	 *
	 * Concrete gateways do not override this. They implement
	 * {@see normalize_event()} and let the orchestrator do the rest.
	 */
	public function handle_webhook( string $slug, array $payload ): \WP_REST_Response {
		$event = $this->normalize_event( $payload );
		if ( null === $event ) {
			return new \WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
		}

		// Idempotency: if we've already processed this provider event, ack and exit.
		if ( '' !== $event->event_id && Idempotency::is_processed( $slug, $this->get_id(), $event->event_id ) ) {
			return new \WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		switch ( $event->type ) {
			case Gateway_Event::TYPE_CHECKOUT_COMPLETED:
				return $this->process_checkout_completed( $slug, $event );

			case Gateway_Event::TYPE_REFUND:
				return $this->process_refund( $slug, $event );
		}

		return new \WP_REST_Response( array( 'received' => true, 'unhandled' => $event->type ), 200 );
	}

	/**
	 * Apply a checkout-completed event:
	 *  - cross-check expected amount + currency against Pending_Checkouts
	 *  - call Credits::topup()
	 *  - record a checkout row in Transaction_Log
	 *  - mark the provider event as processed
	 */
	protected function process_checkout_completed( string $slug, Gateway_Event $event ): \WP_REST_Response {
		$expected = Pending_Checkouts::get( $slug, $event->session_id );
		if ( null === $expected ) {
			return new \WP_REST_Response( array( 'error' => 'unknown_session' ), 404 );
		}
		if ( $event->amount_cents !== $expected['price_cents'] || strtoupper( $event->currency ) !== $expected['currency'] ) {
			return new \WP_REST_Response(
				array(
					'error'    => 'amount_or_currency_mismatch',
					'expected' => array( 'price_cents' => $expected['price_cents'], 'currency' => $expected['currency'] ),
					'actual'   => array( 'price_cents' => $event->amount_cents, 'currency' => strtoupper( $event->currency ) ),
				),
				400
			);
		}

		$ledger_id = Credits::topup(
			$slug,
			(int) $expected['user_id'],
			(int) $expected['credits'],
			sprintf( 'gateway:%s:%s', $this->get_id(), $event->session_id )
		);
		if ( false === $ledger_id ) {
			return new \WP_REST_Response( array( 'error' => 'topup_failed' ), 500 );
		}

		Transaction_Log::insert_checkout(
			array(
				'slug'         => $slug,
				'gateway'      => $this->get_id(),
				'session_id'   => $event->session_id,
				'event_id'     => $event->event_id,
				'user_id'      => (int) $expected['user_id'],
				'credits'      => (int) $expected['credits'],
				'amount_cents' => $event->amount_cents,
				'currency'     => strtoupper( $event->currency ),
				'ledger_id'    => (int) $ledger_id,
			)
		);

		Idempotency::mark_processed( $slug, $this->get_id(), $event->event_id );
		Pending_Checkouts::forget( $slug, $event->session_id );

		/**
		 * Fires after a successful gateway top-up. Lets consumers ship
		 * a confirmation email or push event to user dashboards.
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug
		 * @param int    $user_id
		 * @param int    $credits
		 * @param int    $ledger_id
		 * @param string $gateway_id
		 * @param string $session_id
		 */
		do_action( 'wbcom_credits_gateway_topup', $slug, (int) $expected['user_id'], (int) $expected['credits'], (int) $ledger_id, $this->get_id(), $event->session_id );

		return new \WP_REST_Response(
			array(
				'received'  => true,
				'ledger_id' => $ledger_id,
				'credits'   => $expected['credits'],
			),
			200
		);
	}

	/**
	 * Apply a refund event:
	 *  - look up the parent checkout row in Transaction_Log
	 *  - guard total refunded ≤ amount captured
	 *  - prorate credits to refund: floor( orig_credits * refund_amount / orig_amount )
	 *  - call Credits::adjust(-credits) and append a refund row + bump parent.refunded_cents
	 */
	protected function process_refund( string $slug, Gateway_Event $event ): \WP_REST_Response {
		$parent = Transaction_Log::find_checkout( $slug, $this->get_id(), $event->session_id );
		if ( null === $parent ) {
			// We never recorded the original checkout — ack 200 to stop retries
			// but expose the gap in the error field for support / log analysis.
			return new \WP_REST_Response(
				array(
					'received' => true,
					'warning'  => 'refund_for_unknown_checkout',
					'session'  => $event->session_id,
				),
				200
			);
		}

		$orig_amount   = (int) $parent['amount_cents'];
		$orig_credits  = (int) $parent['credits'];
		$refunded_so_far = (int) $parent['refunded_cents'];
		$refund_amount = $event->amount_cents > 0 ? $event->amount_cents : $orig_amount;

		// Clamp so a misbehaving provider can't refund more than was captured.
		$refund_amount = min( $refund_amount, $orig_amount - $refunded_so_far );
		if ( $refund_amount <= 0 ) {
			return new \WP_REST_Response( array( 'received' => true, 'noop' => 'already_fully_refunded' ), 200 );
		}

		$credits_to_revoke = $orig_amount > 0
			? (int) floor( $orig_credits * $refund_amount / $orig_amount )
			: 0;

		$ledger_id = 0;
		if ( $credits_to_revoke > 0 ) {
			$ledger_id = Credits::adjust(
				$slug,
				(int) $parent['user_id'],
				-$credits_to_revoke,
				sprintf( 'gateway:%s:refund:%s', $this->get_id(), $event->session_id )
			);
			if ( false === $ledger_id ) {
				return new \WP_REST_Response( array( 'error' => 'refund_adjust_failed' ), 500 );
			}
		}

		Transaction_Log::insert_refund(
			array(
				'slug'         => $slug,
				'gateway'      => $this->get_id(),
				'session_id'   => $event->session_id,
				'event_id'     => $event->event_id,
				'user_id'      => (int) $parent['user_id'],
				'credits'      => -$credits_to_revoke,
				'amount_cents' => $refund_amount,
				'currency'     => strtoupper( (string) $parent['currency'] ),
				'ledger_id'    => (int) $ledger_id,
				'parent_id'    => (int) $parent['id'],
			)
		);
		Transaction_Log::add_refunded_amount( $slug, (int) $parent['id'], $refund_amount );
		Idempotency::mark_processed( $slug, $this->get_id(), $event->event_id );

		/**
		 * Fires after a refund has been applied to the credits ledger.
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug
		 * @param int    $user_id
		 * @param int    $credits_revoked Positive integer.
		 * @param int    $ledger_id
		 * @param string $gateway_id
		 * @param string $session_id
		 */
		do_action( 'wbcom_credits_gateway_refund', $slug, (int) $parent['user_id'], $credits_to_revoke, (int) $ledger_id, $this->get_id(), $event->session_id );

		return new \WP_REST_Response(
			array(
				'received'         => true,
				'ledger_id'        => $ledger_id,
				'credits_revoked'  => $credits_to_revoke,
				'amount_refunded'  => $refund_amount,
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Settings access — shared across providers
	// -------------------------------------------------------------------------

	/**
	 * Per-slug option key for gateway settings. All gateways share one row
	 * keyed by gateway id so the consuming plugin's admin UI can save them
	 * with a single options.php submit.
	 */
	final protected static function settings_option_key( string $slug ): string {
		return 'wbcom_credits_gateway_settings_' . sanitize_key( $slug );
	}

	/**
	 * Read this gateway's settings slice for a given slug.
	 *
	 * @return array<string, mixed>
	 */
	final protected function get_settings_for_slug( string $slug ): array {
		$all  = get_option( self::settings_option_key( $slug ), array() );
		$mine = is_array( $all ) ? ( $all[ $this->get_id() ] ?? array() ) : array();
		return is_array( $mine ) ? $mine : array();
	}

	/**
	 * Resolve the active slug from the SDK active-slug filter.
	 *
	 * The default implementation reads the most recently activated slug
	 * from the Wbcom Credits Registry. Multi-slug sites (one site running
	 * Career Board Pro AND Ad Manager Pro at once) can hook
	 * `wbcom_credits_active_slug` to pick the slug their UI is acting on.
	 */
	final protected function active_slug(): string {
		$slug = (string) apply_filters( 'wbcom_credits_active_slug', '' );
		if ( '' !== $slug ) {
			return $slug;
		}
		$slugs = \Wbcom\Credits\Registry::instance()->get_slugs();
		return (string) ( reset( $slugs ) ?: '' );
	}
}
