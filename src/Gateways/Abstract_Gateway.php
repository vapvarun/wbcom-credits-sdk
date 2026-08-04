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

		// Idempotency — claim-then-act. mark_processed() is an atomic
		// INSERT IGNORE on a UNIQUE (slug, gateway, event_id) key: exactly
		// one of N concurrent deliveries of the same event gets `true` and
		// proceeds to credit; every other delivery gets `false` and acks as
		// a duplicate WITHOUT crediting. The claim must happen BEFORE any
		// ledger write so the unique constraint — not a racy in-array check
		// — is what serializes duplicate deliveries.
		//
		// Events with no provider id (event_id === '') cannot be deduped;
		// the gateway is responsible for not emitting creditable events
		// without an id. We let them through here so a provider that omits
		// the id on an otherwise-valid event is not silently dropped.
		if ( '' !== $event->event_id && ! Idempotency::mark_processed( $slug, $this->get_id(), $event->event_id ) ) {
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

		// Session-scoped idempotency claim, shared by BOTH crediting paths
		// (webhook delivery and the synchronous redirect claim added in
		// 1.6.0). The provider-event-id claim in handle_webhook() cannot
		// serialize a webhook racing a redirect claim — the two carry
		// different ids for the same payment — so the session id is the key
		// both paths contend on. Exactly one caller wins and credits; the
		// loser acks as a duplicate. Same atomic INSERT IGNORE mechanism as
		// the event claim.
		if ( ! Idempotency::mark_processed( $slug, $this->get_id(), 'session:' . $event->session_id ) ) {
			return new \WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		// Money consumers store MINOR units in the ledger, and a credit
		// count is a MAJOR-unit amount by definition (the same single-
		// boundary rule 1.5.1 applied to adapter mappings — which missed
		// this path, so every gateway purchase on a money consumer was
		// credited at 1/minor-factor of what the buyer paid for). Token
		// consumers take the integer verbatim, exactly as before.
		$credits_purchased = (int) $expected['credits'];
		$topup_note        = sprintf( 'gateway:%s:%s', $this->get_id(), $event->session_id );

		$ledger_id = Credits::is_money( $slug )
			? Credits::topup_money( $slug, (int) $expected['user_id'], $credits_purchased, '', $topup_note )
			: Credits::topup( $slug, (int) $expected['user_id'], $credits_purchased, $topup_note );
		if ( false === $ledger_id ) {
			return new \WP_REST_Response( array( 'error' => 'topup_failed' ), 500 );
		}

		Transaction_Log::insert_checkout(
			array(
				'slug'           => $slug,
				'gateway'        => $this->get_id(),
				'session_id'     => $event->session_id,
				'payment_intent' => $event->provider_ref,
				'event_id'       => $event->event_id,
				'user_id'        => (int) $expected['user_id'],
				'credits'        => (int) $expected['credits'],
				'amount_cents'   => $event->amount_cents,
				'currency'       => strtoupper( $event->currency ),
				'ledger_id'      => (int) $ledger_id,
			)
		);

		// Event was already claimed atomically in handle_webhook() before we
		// reached this point, so no mark_processed() call is needed here.
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
			// Same money-mode boundary as the topup above: a credit count is
			// a MAJOR-unit amount on money consumers.
			$refund_note = sprintf( 'gateway:%s:refund:%s', $this->get_id(), $event->session_id );
			$ledger_id   = Credits::is_money( $slug )
				? Credits::adjust_money( $slug, (int) $parent['user_id'], -$credits_to_revoke, '', $refund_note )
				: Credits::adjust( $slug, (int) $parent['user_id'], -$credits_to_revoke, $refund_note );
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
		// Event was already claimed atomically in handle_webhook(); no
		// mark_processed() call is needed here.

		// Fire the SDK's generic refund event so consumer plugins' refund
		// consumers (audit log, outgoing webhooks, notifications) run for
		// gateway-initiated refunds too. The gateway revokes credits via
		// Credits::adjust(), which intentionally does NOT fire SDK actions,
		// so without this the documented `wbcom_credits_refunded` contract
		// would be silently skipped for every Stripe/PayPal refund. We only
		// fire when credits were actually revoked.
		//
		// Signature (since 1.4.0): ($slug, $user_id, $amount, $context). The
		// 3rd arg carries the REVOKED CREDIT COUNT (a positive int) and the
		// 4th a linkage-context map so Pro consumers can attribute the refund
		// to a ledger row / listing and reverse the right perk. The 4th arg is
		// additive — existing 3-arg listeners keep working — but note the 3rd
		// arg is now the amount, not item_id (item_id moved into $context).
		if ( $credits_to_revoke > 0 ) {
			$context = array(
				'gateway'      => $this->get_id(),
				'session_id'   => $event->session_id,
				'provider_ref' => $event->provider_ref,
				'ledger_id'    => (int) $ledger_id,
				'reason'       => 'gateway_refund',
				'item_id'      => 0,
			);
			/**
			 * Fires after credits are refunded. Mirrors {@see \Wbcom\Credits\Credits::refund()}
			 * so consumer event bridges see gateway-initiated refunds identically
			 * to hold-lifecycle refunds.
			 *
			 * @since 1.0.0
			 * @since 1.4.0 3rd arg is the revoked credit amount; 4th arg ($context) added.
			 *
			 * @param string               $slug    Plugin slug.
			 * @param int                  $user_id WordPress user ID.
			 * @param int                  $amount  Credits revoked (positive int).
			 * @param array<string, mixed> $context Linkage context: gateway, session_id,
			 *                                       provider_ref, ledger_id, reason, item_id.
			 */
			do_action( 'wbcom_credits_refunded', $slug, (int) $parent['user_id'], $credits_to_revoke, $context );
		}

		/**
		 * Fires after a refund has been applied to the credits ledger.
		 * Gateway-scoped companion to the generic `wbcom_credits_refunded`
		 * action, carrying the richer gateway context (revoked count,
		 * ledger row, gateway id, session id).
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
	// Synchronous redirect claim (1.6.0)
	// -------------------------------------------------------------------------

	/**
	 * Ask the provider whether a checkout session has been paid, without a
	 * webhook — the synchronous half of credit granting.
	 *
	 * Gateways that can verify a session server-side (Stripe: retrieve the
	 * Checkout Session with the secret key) override this and return a
	 * checkout-completed {@see Gateway_Event} when — and only when — the
	 * provider confirms payment. Gateways that cannot verify synchronously
	 * keep this default and stay webhook-only.
	 *
	 * The returned event has an empty event_id (a session lookup is not a
	 * provider event); idempotency for this path is carried by the
	 * session-scoped claim inside {@see process_checkout_completed()}.
	 *
	 * @since 1.6.0
	 *
	 * @param string $slug       Consumer plugin slug.
	 * @param string $session_id Provider checkout/session id.
	 * @return Gateway_Event|null Paid checkout event, or null when the
	 *                            gateway cannot confirm payment (unpaid,
	 *                            unknown, or verification unsupported).
	 */
	public function retrieve_checkout_event( string $slug, string $session_id ): ?Gateway_Event {
		return null;
	}

	/**
	 * Credit a checkout on redirect-return, verified against the provider.
	 *
	 * Webhooks remain the canonical path, but a site owner who never
	 * configures one (or whose site the provider cannot reach — local,
	 * staging, firewalled) must still deliver credits the moment the buyer
	 * returns: taking the payment and granting nothing is the worst
	 * failure a payment feature can have. This claim path trusts nothing
	 * from the browser except the session id — payment state, amount, and
	 * currency all come from the provider lookup and are cross-checked
	 * against Pending_Checkouts exactly like a webhook delivery.
	 *
	 * Double-crediting against a racing webhook is impossible: both paths
	 * pass through {@see process_checkout_completed()}, which atomically
	 * claims the session id before writing the ledger.
	 *
	 * @since 1.6.0
	 *
	 * @param string $slug       Consumer plugin slug.
	 * @param string $session_id Provider checkout/session id.
	 * @return \WP_REST_Response Same envelope process_checkout_completed()
	 *                           returns, or `{pending: true}` (202) when the
	 *                           provider has not confirmed payment yet.
	 */
	public function claim_checkout( string $slug, string $session_id ): \WP_REST_Response {
		$event = $this->retrieve_checkout_event( $slug, $session_id );
		if ( null === $event ) {
			return new \WP_REST_Response(
				array(
					'received' => true,
					'pending'  => true,
					'note'     => 'Payment not confirmed by the provider yet. Credits land when it confirms (or via webhook).',
				),
				202
			);
		}

		return $this->process_checkout_completed( $slug, $event );
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
