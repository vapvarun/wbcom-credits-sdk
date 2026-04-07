<?php
/**
 * WooCommerce Memberships adapter — awards credits on membership activation.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for WooCommerce Memberships status changes and tops up credits
 * when a membership becomes active based on plan-to-credit mappings.
 *
 * @since 1.0.0
 */
final class WooMembershipsAdapter implements AdapterInterface {

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
		return 'woo_memberships';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'WooCommerce Memberships', 'wbcom-credits-sdk' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return function_exists( 'wc_memberships' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( string $slug ): void {
		$this->slug   = $slug;
		$this->prefix = $this->resolve_prefix( $slug );

		add_action( 'wc_memberships_user_membership_status_changed', array( $this, 'on_membership_status_changed' ), 10, 3 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_mappable_items(): array {
		if ( ! $this->is_available() || ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
			return array();
		}

		$items = array();
		$plans = wc_memberships_get_membership_plans();

		foreach ( $plans as $plan ) {
			$items[] = array(
				'id'    => $plan->get_id(),
				'label' => $plan->get_name(),
			);
		}

		return $items;
	}

	/**
	 * Handle membership status changes.
	 *
	 * Awards credits when a membership transitions to an active status.
	 * Uses a meta flag on the membership to prevent double-processing.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Memberships_User_Membership $membership The membership object.
	 * @param string                          $old_status Previous status.
	 * @param string                          $new_status New status.
	 * @return void
	 */
	public function on_membership_status_changed( $membership, string $old_status, string $new_status ): void {
		// Only process when transitioning to active.
		if ( 'active' !== $new_status && 'wcm-active' !== $new_status ) {
			return;
		}

		$membership_id = $membership->get_id();

		// Prevent double-processing.
		$processed = get_post_meta( $membership_id, '_wbcom_credits_processed', true );
		if ( $processed ) {
			return;
		}

		$user_id = $membership->get_user_id();
		$plan_id = $membership->get_plan_id();

		if ( ! $user_id || ! $plan_id ) {
			return;
		}

		$registry = $this->get_registry();
		$credits  = $registry->lookup_credits( $this->get_id(), $plan_id );

		if ( $credits > 0 ) {
			$plan_name = $membership->get_plan()->get_name();
			$note      = sprintf(
				/* translators: %s: membership plan name. */
				__( 'Credits from WooCommerce Membership: %s', 'wbcom-credits-sdk' ),
				$plan_name
			);

			\Wbcom\Credits\Credits::topup( $this->slug, $user_id, $credits, $note );
		}

		update_post_meta( $membership_id, '_wbcom_credits_processed', '1' );
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
