<?php
/**
 * Credit a paid order and record it.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

use Wbcom\Credits\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * The one place an order becomes credits: the top-up (with the pack's expiry),
 * the Transaction_Log row (what was charged, the coupon, the billing
 * snapshot), and the events consumers hang receipts and emails on. Used by
 * gateway completions (webhook, return claim, reconcile sweep) and by orders
 * a coupon made free, so every path records the same thing.
 *
 * @since 1.9.0
 */
final class Fulfilment {

	/**
	 * Credit the buyer and record the order.
	 *
	 * @since 1.9.0
	 * @param string               $slug         Plugin slug.
	 * @param int                  $user_id      Buyer.
	 * @param int                  $credits      Credits bought (major units for money consumers).
	 * @param array<string, mixed> $order        Order::build() parts (may be empty for pre-1.9.0 sessions).
	 * @param string               $gateway_id   Gateway id ('free' for a zero-total order).
	 * @param string               $session_id   Provider session id.
	 * @param string               $event_id     Provider event id.
	 * @param string               $provider_ref Provider payment reference.
	 * @param int                  $amount       Amount charged, minor units.
	 * @param string               $currency     ISO 4217 code.
	 * @return array{ledger_id: int, log_id: int}|null Null when the top-up failed.
	 */
	public static function credit( string $slug, int $user_id, int $credits, array $order, string $gateway_id, string $session_id, string $event_id, string $provider_ref, int $amount, string $currency ): ?array {
		$days    = (int) ( $order['expires_days'] ?? 0 );
		$expires = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null;
		$note    = sprintf( 'gateway:%s:%s', $gateway_id, $session_id );

		// Money consumers store MINOR units; a credit count is a MAJOR-unit
		// amount by definition (single-boundary rule, 1.5.1).
		// Credit and log together: joins the caller's transaction (the
		// gateway's claim), or is its own for a free order.
		\Wbcom\Credits\Ledger::begin();
		$ledger_id = Credits::is_money( $slug )
			? Credits::topup_money( $slug, $user_id, $credits, '', $note, $expires, 'purchase', $note )
			: Credits::topup( $slug, $user_id, $credits, $note, $expires, 'purchase', $note );
		if ( false === $ledger_id ) {
			\Wbcom\Credits\Ledger::rollback();
			return null;
		}

		$log_id = Transaction_Log::insert_checkout(
			array(
				'slug'           => $slug,
				'gateway'        => $gateway_id,
				'session_id'     => $session_id,
				'payment_intent' => $provider_ref,
				'event_id'       => $event_id,
				'user_id'        => $user_id,
				'credits'        => $credits,
				'amount_cents'   => $amount,
				'currency'       => strtoupper( $currency ),
				'ledger_id'      => (int) $ledger_id,
				'subtotal_cents' => (int) ( $order['subtotal'] ?? $amount ),
				'discount_cents' => (int) ( $order['discount'] ?? 0 ),
				'tax_cents'      => (int) ( $order['tax'] ?? 0 ),
				'coupon'         => (string) ( $order['coupon'] ?? '' ),
				'billing'        => (array) ( $order['billing'] ?? array() ),
			)
		);
		\Wbcom\Credits\Ledger::commit();

		/**
		 * Fires after a successful gateway top-up.
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
		$gateway_topup_args = array( $slug, $user_id, $credits, (int) $ledger_id, $gateway_id, $session_id );
		\Wbcom\Credits\Ledger::after_commit( static fn () => do_action( 'wbcom_credits_gateway_topup', ...$gateway_topup_args ) );

		/**
		 * Fires once a purchase is paid and recorded - the hook for receipts.
		 *
		 * Receipt::data( $slug, $log_id ) has everything a receipt shows;
		 * Receipt::url( $slug, $log_id ) is its printable page.
		 *
		 * @since 1.9.0
		 *
		 * @param string $slug    Plugin slug.
		 * @param int    $user_id Buyer.
		 * @param int    $log_id  Transaction_Log row id.
		 */
		$purchase_completed_args = array( $slug, $user_id, $log_id );
		\Wbcom\Credits\Ledger::after_commit( static fn () => do_action( 'wbcom_credits_purchase_completed', ...$purchase_completed_args ) );

		return array(
			'ledger_id' => (int) $ledger_id,
			'log_id'    => $log_id,
		);
	}
}
