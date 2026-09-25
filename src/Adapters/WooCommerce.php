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

		// A refund or cancellation takes back what the order granted. Nothing
		// did before 1.7.2: a fully refunded credit order left the buyer with
		// all its credits.
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ), 10 );

		// While the consumer has selling switched off, its credit products
		// cannot be bought: no Add to cart, and WooCommerce drops one already
		// in the cart at checkout. Orders paid before still credit.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'gate_purchasable' ), 10, 2 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'print_unavailable_notice' ), 31 );
	}

	/**
	 * Credit products are not purchasable while selling is off.
	 *
	 * @since 1.7.2
	 *
	 * @param bool  $purchasable WooCommerce's answer.
	 * @param mixed $product     WC_Product.
	 * @return bool
	 */
	public function gate_purchasable( $purchasable, $product ): bool {
		return $purchasable && ! $this->is_blocked( $product );
	}

	/**
	 * Say why a credit product has no Add to cart button.
	 *
	 * @since 1.7.2
	 */
	public function print_unavailable_notice(): void {
		global $product;
		if ( $this->is_blocked( $product ) ) {
			echo '<p class="wbcom-credits-unavailable">' . esc_html__( 'Credit purchases are not available on this site right now.', 'wbcom-credits-sdk' ) . '</p>';
		}
	}

	/**
	 * Whether a product sells this consumer's credits while selling is off.
	 *
	 * Checks the product and, for a variation, its parent, under this adapter
	 * and the WooCommerce Subscriptions adapter (both sell WC products).
	 *
	 * @param mixed $product WC_Product.
	 * @return bool
	 */
	private function is_blocked( $product ): bool {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || \Wbcom\Credits\Credits::checkout_enabled( $this->slug ) ) {
			return false;
		}
		$registry = $this->get_registry();
		$ids      = array( (int) $product->get_id(), method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0 );
		foreach ( array_filter( $ids ) as $id ) {
			if ( $registry->lookup_credits( $this->get_id(), $id ) > 0 || $registry->lookup_credits( 'woo_subscriptions', $id ) > 0 ) {
				return true;
			}
		}
		return false;
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

			// What this order granted, in ledger units, so a refund revokes
			// exactly that even if the mapping changes later.
			$order->update_meta_data( $this->granted_meta_key(), $total_credits );
		}

		// Keep the legacy meta flag as a human-readable marker for support /
		// reconciliation. It is NO LONGER the dedupe guard — the atomic claim
		// above is — so a save() failure here cannot cause a double top-up.
		$order->update_meta_data( '_wbcom_credits_processed', '1' );
		$order->save();
	}

	/**
	 * Revoke the refunded share of an order's credits (full or partial refund).
	 *
	 * Revokes up to the order's refunded fraction of what it granted, minus
	 * anything already revoked, so several partial refunds add up to the grant
	 * and never past it. Claimed once per refund id, so a re-fired hook for the
	 * same refund is a no-op. The balance may go negative when the credits were
	 * already spent, as it does for a gateway refund.
	 *
	 * @since 1.7.2
	 *
	 * @param int $order_id  WooCommerce order ID.
	 * @param int $refund_id WooCommerce refund ID.
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id = 0 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}

		$granted = $this->granted_credits( $order );
		if ( $granted <= 0 ) {
			return;
		}

		if ( ! Processed_Events::claim( $this->slug, 'adapter:' . $this->get_id(), 'woo:refund:' . (int) $refund_id ) ) {
			return;
		}

		$total    = (float) $order->get_total();
		$refunded = (float) $order->get_total_refunded();
		$share    = $total > 0 ? min( 1.0, $refunded / $total ) : 1.0;

		$this->revoke_up_to(
			$order,
			(int) round( $granted * $share ),
			sprintf(
				/* translators: %d: WooCommerce order number. */
				__( 'Refund of WooCommerce order #%d', 'wbcom-credits-sdk' ),
				(int) $order_id
			),
			'woo:refund:' . (int) $refund_id
		);
	}

	/**
	 * Revoke the rest of a credited order's credits when it is cancelled.
	 *
	 * @since 1.7.2
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function on_order_cancelled( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}

		$granted = $this->granted_credits( $order );
		if ( $granted <= 0 ) {
			return;
		}

		if ( ! Processed_Events::claim( $this->slug, 'adapter:' . $this->get_id(), 'woo:cancel:' . (int) $order_id ) ) {
			return;
		}

		$this->revoke_up_to(
			$order,
			$granted,
			sprintf(
				/* translators: %d: WooCommerce order number. */
				__( 'Cancelled WooCommerce order #%d', 'wbcom-credits-sdk' ),
				(int) $order_id
			),
			'woo:cancel:' . (int) $order_id
		);
	}

	/**
	 * Bring an order's revoked total up to $target and announce the refund.
	 *
	 * @param \WC_Order $order        Order.
	 * @param int       $target       Ledger units that should be revoked in total.
	 * @param string    $note         Ledger note.
	 * @param string    $provider_ref Reference for the refund event context.
	 * @return void
	 */
	private function revoke_up_to( $order, int $target, string $note, string $provider_ref ): void {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$revoked = (int) $order->get_meta( $this->revoked_meta_key() );
		$delta   = $target - $revoked;
		if ( $delta <= 0 ) {
			return;
		}

		$ledger_id = \Wbcom\Credits\Credits::adjust( $this->slug, $user_id, -$delta, $note );
		if ( false === $ledger_id ) {
			return;
		}

		$order->update_meta_data( $this->revoked_meta_key(), $revoked + $delta );
		$order->save();

		/** This action is documented in src/Gateways/Abstract_Gateway.php */
		do_action(
			'wbcom_credits_refunded',
			$this->slug,
			$user_id,
			$delta,
			array(
				'gateway'      => 'woocommerce',
				'session_id'   => 'woo:order:' . (int) $order->get_id(),
				'provider_ref' => $provider_ref,
				'ledger_id'    => (int) $ledger_id,
				'reason'       => 'gateway_refund',
				'item_id'      => 0,
			)
		);
	}

	/**
	 * Ledger units this order granted, or 0 when it granted none.
	 *
	 * Orders credited before 1.7.2 carry no granted-meta; for those the grant
	 * is recomputed from the current mapping, but only when the order was
	 * actually credited (its dedupe claim exists).
	 *
	 * @param \WC_Order $order Order.
	 * @return int
	 */
	private function granted_credits( $order ): int {
		$stored = $order->get_meta( $this->granted_meta_key() );
		if ( '' !== $stored && null !== $stored ) {
			return (int) $stored;
		}

		if ( ! Processed_Events::exists( $this->slug, 'adapter:' . $this->get_id(), 'woo:order:' . (int) $order->get_id() ) ) {
			return 0;
		}

		$registry = $this->get_registry();
		$total    = 0;
		foreach ( $order->get_items() as $item ) {
			$credits = $registry->lookup_credits( $this->get_id(), $item->get_product_id() );
			if ( $credits > 0 ) {
				$total += $credits * (int) $item->get_quantity();
			}
		}

		return $total;
	}

	/**
	 * Order meta holding the ledger units this consumer granted for the order.
	 *
	 * @return string
	 */
	private function granted_meta_key(): string {
		return '_wbcom_credits_granted_' . sanitize_key( $this->slug );
	}

	/**
	 * Order meta holding the ledger units already revoked for the order.
	 *
	 * @return string
	 */
	private function revoked_meta_key(): string {
		return '_wbcom_credits_revoked_' . sanitize_key( $this->slug );
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
