<?php
/**
 * Paid Memberships Pro adapter — awards credits on membership level changes and recurring payments.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

use Wbcom\Credits\Gateways\Processed_Events;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for PMPro membership level changes and subscription renewal payments,
 * then tops up credits based on level-to-credit mappings.
 *
 * @since 1.0.0
 */
final class PMProAdapter implements AdapterInterface {

	/**
	 * Consuming plugin slug.
	 *
	 * @var string
	 */
	private string $slug = '';

	/**
	 * Consuming plugin DB table prefix.
	 *
	 * @var string
	 */
	private string $prefix = '';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'pmpro';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Paid Memberships Pro', 'wbcom-credits-sdk' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'PMPRO_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( string $slug ): void {
		$this->slug   = $slug;
		$this->prefix = $this->resolve_prefix( $slug );

		add_action( 'pmpro_after_change_membership_level', array( $this, 'on_level_change' ), 10, 2 );
		add_action( 'pmpro_subscription_payment_completed', array( $this, 'on_subscription_payment' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_mappable_items(): array {
		if ( ! $this->is_available() || ! function_exists( 'pmpro_getAllLevels' ) ) {
			return array();
		}

		$items  = array();
		$levels = pmpro_getAllLevels( false, true );

		foreach ( $levels as $level ) {
			$items[] = array(
				'id'    => (int) $level->id,
				'label' => $level->name,
			);
		}

		return $items;
	}

	/**
	 * Handle membership level change.
	 *
	 * Awards credits when a user is assigned a new membership level.
	 * Does not process cancellations (level_id = 0).
	 *
	 * Double-processing is guarded by an ATOMIC claim, not a read-then-write
	 * user-meta flag. A `get_user_meta()` … `update_user_meta()` guard has a
	 * TOCTOU window: two concurrent deliveries of the same level change (e.g.
	 * a retried `pmpro_after_change_membership_level` hook) can both read
	 * "not granted today" before either saves, and both top up.
	 * {@see Processed_Events::claim()} is a UNIQUE `INSERT IGNORE` that
	 * returns true for exactly one of N racing deliveries — so we claim
	 * FIRST and only credit when we won the claim.
	 *
	 * @since 1.0.0
	 *
	 * @param int $level_id New membership level ID (0 on cancellation).
	 * @param int $user_id  WordPress user ID.
	 * @return void
	 */
	public function on_level_change( int $level_id, int $user_id ): void {
		// Level 0 = cancellation, skip.
		if ( 0 === $level_id ) {
			return;
		}

		$today = wp_date( 'Y-m-d' );

		// Atomic dedupe: claim BEFORE crediting, once per user+level+day.
		if ( ! Processed_Events::claim( $this->slug, 'adapter:' . $this->get_id(), 'pmpro:level:' . $user_id . ':' . $level_id . ':' . $today ) ) {
			return;
		}

		$registry = $this->get_registry();
		$credits  = $registry->lookup_credits( $this->get_id(), $level_id );

		if ( $credits > 0 ) {
			$level_obj = pmpro_getLevel( $level_id );
			$note      = sprintf(
				/* translators: %s: membership level name. */
				__( 'Credits from PMPro membership: %s', 'wbcom-credits-sdk' ),
				$level_obj ? $level_obj->name : (string) $level_id
			);

			\Wbcom\Credits\Credits::topup( $this->slug, $user_id, $credits, $note );

			// Keep the legacy meta flag as a human-readable marker for
			// support / reconciliation. It is NO LONGER the dedupe guard —
			// the atomic claim above is — so a save() failure here cannot
			// cause a double top-up.
			update_user_meta( $user_id, '_wbcom_credits_pmpro_level_' . $level_id, $today );
		}
	}

	/**
	 * Handle recurring subscription payment.
	 *
	 * Awards credits on each successful renewal payment. Uses the order's
	 * membership level to determine the credit amount.
	 *
	 * Double-processing is guarded by an ATOMIC claim — see
	 * {@see on_level_change()} for why a read-then-write meta flag is unsafe.
	 *
	 * @since 1.0.0
	 *
	 * @param \MemberOrder $order PMPro order object.
	 * @return void
	 */
	public function on_subscription_payment( $order ): void {
		if ( ! is_object( $order ) ) {
			return;
		}

		$user_id  = (int) ( $order->user_id ?? 0 );
		$level_id = (int) ( $order->membership_id ?? 0 );

		if ( ! $user_id || ! $level_id ) {
			return;
		}

		$order_id = $order->id ?? 0;

		// Atomic dedupe: claim BEFORE crediting.
		if ( ! Processed_Events::claim( $this->slug, 'adapter:' . $this->get_id(), 'pmpro:order:' . $order_id ) ) {
			return;
		}

		$registry = $this->get_registry();
		$credits  = $registry->lookup_credits( $this->get_id(), $level_id );

		if ( $credits > 0 ) {
			$note = sprintf(
				/* translators: %d: PMPro order ID. */
				__( 'Credits from PMPro recurring payment — order #%d', 'wbcom-credits-sdk' ),
				$order_id
			);

			\Wbcom\Credits\Credits::topup( $this->slug, $user_id, $credits, $note );

			// Keep the legacy meta flag as a human-readable marker for
			// support / reconciliation. It is NO LONGER the dedupe guard.
			update_user_meta( $user_id, '_wbcom_credits_pmpro_order_' . $order_id, '1' );
		}
	}

	/**
	 * Build an AdapterRegistry scoped to this adapter's consuming plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return AdapterRegistry
	 */
	private function get_registry(): AdapterRegistry {
		return new AdapterRegistry( $this->slug, $this->prefix );
	}

	/**
	 * Resolve the DB prefix for a plugin slug from the central registry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Plugin slug.
	 * @return string DB prefix.
	 */
	private function resolve_prefix( string $slug ): string {
		$config = \Wbcom\Credits\Registry::instance()->get( $slug );
		return $config['prefix'] ?? $slug;
	}
}
