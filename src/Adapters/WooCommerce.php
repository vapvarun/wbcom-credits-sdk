<?php
/**
 * WooCommerce adapter — awards credits on completed orders.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

use Wbcom\Credits\Gateways\Processed_Events;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for WooCommerce order completion and tops up credits
 * based on the product-to-credit mappings stored by the consuming plugin.
 *
 * @since 1.0.0
 */
final class WooCommerceAdapter implements AdapterInterface {

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
		return 'woocommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'WooCommerce', 'wbcom-credits-sdk' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( string $slug ): void {
		$this->slug   = $slug;
		$this->prefix = $this->resolve_prefix( $slug );

		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 9 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_order_completed' ), 9 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_mappable_items(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$items    = array();
		$products = wc_get_products(
			array(
				'status' => 'publish',
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
	 * Handle a completed WooCommerce order.
	 *
	 * Iterates over order items, looks up credit mappings, and tops up the
	 * customer's credit balance.
	 *
	 * Double-processing is guarded by an ATOMIC claim, not an order-meta flag.
	 * Both `woocommerce_order_status_completed` and `_status_processing` fire
	 * for the same order, and Woo can dispatch the same status transition from
	 * concurrent requests (webhook + admin, or two payment IPNs). A read-then-
	 * write meta flag (`get_meta()` … `save()`) has a TOCTOU window: two
	 * deliveries can both read "not processed" before either saves, and both
	 * top up. {@see Processed_Events::claim()} is a UNIQUE `INSERT IGNORE`
	 * that returns true for exactly one of N racing deliveries — so we claim
	 * FIRST and only credit when we won the claim.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function on_order_completed( $order_id ): void {
		$order_id = (int) $order_id;
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip subscription orders — handled by WooSubscriptions adapter.
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		// Do not credit an order nobody has paid for.
		//
		// This handler runs on `processing` as well as `completed`, and Cash on
		// Delivery, cheque and BACS all move an UNPAID order to `processing`.
		// Without this a buyer could place a COD order, receive the credits
		// immediately, spend them on a plan, and never pay.
		//
		// The test is `get_date_paid()`, NOT `is_paid()`. `is_paid()` asks
		// whether the STATUS is one of the paid statuses, and `processing` is
		// one of them — it returns true for an unpaid COD order and for a
		// genuinely captured card order alike, so guarding on it would look
		// correct and change nothing. WooCommerce stamps `date_paid` when money
		// is actually taken (`payment_complete()`), and also when a COD order is
		// finally marked completed, so this admits every real payment without
		// needing a gateway allowlist.
		//
		// Placed BEFORE the dedupe claim on purpose: claiming first would burn
		// the event id on an unpaid order, and the later `completed` transition
		// would find the claim already taken and never credit at all — turning
		// crediting too early into never crediting.
		if ( ! $order->get_date_paid() ) {
			return;
		}

		// Atomic dedupe: claim BEFORE crediting. A stable per-order event id
		// keyed under this adapter's slug + an adapter-tagged gateway means a
		// second delivery of the same order (or the processing→completed pair)
		// loses the claim and exits without crediting again.
		if ( ! Processed_Events::claim( $this->slug, 'adapter:' . $this->get_id(), 'woo:order:' . $order_id ) ) {
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
				/* translators: %d: WooCommerce order number. */
				__( 'Credits from WooCommerce order #%d', 'wbcom-credits-sdk' ),
				$order_id
			);

			\Wbcom\Credits\Credits::topup( $this->slug, $user_id, $total_credits, $note );
		}

		// Keep the legacy meta flag as a human-readable marker for support /
		// reconciliation. It is NO LONGER the dedupe guard — the atomic claim
		// above is — so a save() failure here cannot cause a double top-up.
		$order->update_meta_data( '_wbcom_credits_processed', '1' );
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
