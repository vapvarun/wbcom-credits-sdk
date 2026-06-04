<?php
/**
 * WooCommerce Subscriptions adapter — awards credits on subscription payments.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

use Wbcom\Credits\Gateways\Processed_Events;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for WooCommerce Subscriptions renewal and initial payment events
 * and tops up credits based on subscription-product-to-credit mappings.
 *
 * @since 1.0.0
 */
final class WooSubscriptionsAdapter implements AdapterInterface {

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
		return 'woo_subscriptions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'WooCommerce Subscriptions', 'wbcom-credits-sdk' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return class_exists( 'WC_Subscriptions' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( string $slug ): void {
		$this->slug   = $slug;
		$this->prefix = $this->resolve_prefix( $slug );

		add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'on_renewal_payment' ), 9, 2 );
		add_action( 'woocommerce_subscription_payment_complete', array( $this, 'on_initial_payment' ), 9 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_mappable_items(): array {
		if ( ! $this->is_available() || ! function_exists( 'wcs_get_subscriptions' ) ) {
			return array();
		}

		$items    = array();
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'type'   => array( 'subscription', 'variable-subscription' ),
				'limit'  => -1,
				'return' => 'objects',
			)
		);

		foreach ( $products as $product ) {
			$items[] = array(
				'id'    => $product->get_id(),
				'label' => $product->get_name(),
			);
		}

		return $items;
	}

	/**
	 * Handle subscription renewal payment.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Subscription $subscription The subscription object.
	 * @param \WC_Order        $order        The renewal order.
	 * @return void
	 */
	public function on_renewal_payment( $subscription, $order ): void {
		$this->process_subscription_payment( $subscription, $order, 'renewal' );
	}

	/**
	 * Handle initial subscription payment.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Subscription $subscription The subscription object.
	 * @return void
	 */
	public function on_initial_payment( $subscription ): void {
		$last_order = $subscription->get_last_order( 'all' );
		if ( ! $last_order ) {
			return;
		}

		$this->process_subscription_payment( $subscription, $last_order, 'initial' );
	}

	/**
	 * Process a subscription payment event and top up credits.
	 *
	 * Dedupe is via an ATOMIC claim, not an order-meta flag. The initial-
	 * payment and renewal-payment hooks can both resolve to the same order,
	 * and a renewal IPN can be delivered concurrently with a manual "process
	 * renewal" admin action. A read-then-write meta flag has a TOCTOU window
	 * where two deliveries both read "not processed" and both top up. The
	 * claim is keyed per ORDER (each renewal is its own order, so every billing
	 * period still credits exactly once) under this adapter's slug + an
	 * adapter-tagged gateway. {@see Processed_Events::claim()} returns true for
	 * exactly one of N racing deliveries.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Subscription $subscription The subscription object.
	 * @param \WC_Order        $order        The associated order.
	 * @param string           $type         Payment type: 'initial' or 'renewal'.
	 * @return void
	 */
	private function process_subscription_payment( $subscription, $order, string $type ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id = $order->get_id();

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		// Atomic dedupe: claim BEFORE crediting. Keyed per order so each
		// renewal order credits once, but a duplicate delivery of the same
		// order (initial+renewal hook overlap, or concurrent IPNs) loses the
		// claim and exits.
		if ( ! Processed_Events::claim( $this->slug, 'adapter:' . $this->get_id(), 'woosub:order:' . $order_id ) ) {
			return;
		}

		$registry      = $this->get_registry();
		$total_credits = 0;

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$quantity   = $item->get_quantity();
			$credits    = $registry->lookup_credits( $this->get_id(), $product_id );

			if ( $credits > 0 ) {
				$total_credits += $credits * $quantity;
			}
		}

		if ( $total_credits > 0 ) {
			$note = sprintf(
				/* translators: 1: payment type (initial/renewal), 2: order number. */
				__( 'Credits from subscription %1$s payment — order #%2$d', 'wbcom-credits-sdk' ),
				$type,
				$order_id
			);

			\Wbcom\Credits\Credits::topup( $this->slug, $user_id, $total_credits, $note );
		}

		// Legacy marker for support / reconciliation only; no longer the guard.
		$order->update_meta_data( '_wbcom_sub_credits_processed', '1' );
		$order->save();
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
