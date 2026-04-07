<?php
/**
 * WooCommerce adapter — awards credits on completed orders.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

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
	 * customer's credit balance. Uses a meta flag to prevent double-processing.
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

		// Prevent double-processing.
		if ( $order->get_meta( '_wbcom_credits_processed' ) ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
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
