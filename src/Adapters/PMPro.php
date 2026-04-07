<?php
/**
 * Paid Memberships Pro adapter — awards credits on membership level changes and recurring payments.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

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

		// Prevent double-processing via user meta.
		$meta_key   = '_wbcom_credits_pmpro_level_' . $level_id;
		$last_grant = get_user_meta( $user_id, $meta_key, true );
		$today      = wp_date( 'Y-m-d' );

		if ( $last_grant === $today ) {
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
			update_user_meta( $user_id, $meta_key, $today );
		}
	}

	/**
	 * Handle recurring subscription payment.
	 *
	 * Awards credits on each successful renewal payment. Uses the order's
	 * membership level to determine the credit amount.
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

		// Prevent double-processing via order meta.
		$order_id  = $order->id ?? 0;
		$processed = get_user_meta( $user_id, '_wbcom_credits_pmpro_order_' . $order_id, true );
		if ( $processed ) {
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
