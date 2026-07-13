<?php
/**
 * MemberPress adapter — awards credits on completed transactions.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

use Wbcom\Credits\Gateways\Processed_Events;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for MemberPress transaction completion events and tops up credits
 * based on membership-to-credit mappings.
 *
 * @since 1.0.0
 */
final class MemberPressAdapter implements AdapterInterface {

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
		return 'memberpress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'MemberPress', 'wbcom-credits-sdk' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'MEPR_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks( string $slug ): void {
		$this->slug   = $slug;
		$this->prefix = $this->resolve_prefix( $slug );

		add_action( 'mepr_event_transaction_completed', array( $this, 'on_transaction_completed' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_mappable_items(): array {
		if ( ! $this->is_available() || ! class_exists( 'MeprProduct' ) ) {
			return array();
		}

		$items      = array();
		$membership = \MeprProduct::get_all();

		foreach ( $membership as $product ) {
			$post = get_post( $product->ID );
			if ( $post ) {
				$items[] = array(
					'id'    => (int) $product->ID,
					'label' => $post->post_title,
				);
			}
		}

		return $items;
	}

	/**
	 * Handle a completed MemberPress transaction event.
	 *
	 * Extracts the transaction from the event, atomically claims the
	 * transaction id to guard against double-processing, and tops up
	 * credits.
	 *
	 * Double-processing was previously guarded by a `note LIKE '%...%'`
	 * scan of the SDK ledger table — a non-atomic read-then-write that two
	 * concurrent deliveries of the same transaction could both pass before
	 * either topped up. {@see Processed_Events::claim()} is a UNIQUE
	 * `INSERT IGNORE` that returns true for exactly one of N racing
	 * deliveries — so we claim FIRST and only credit when we won the claim.
	 *
	 * @since 1.0.0
	 *
	 * @param \MeprEvent $event MemberPress event object.
	 * @return void
	 */
	public function on_transaction_completed( $event ): void {
		if ( ! is_object( $event ) || ! method_exists( $event, 'get_data' ) ) {
			return;
		}

		$txn = $event->get_data();

		if ( ! is_object( $txn ) || ! isset( $txn->user_id, $txn->product_id, $txn->id ) ) {
			return;
		}

		$user_id    = (int) $txn->user_id;
		$product_id = (int) $txn->product_id;
		$txn_id     = (int) $txn->id;

		if ( ! $user_id || ! $product_id ) {
			return;
		}

		// Atomic dedupe: claim BEFORE crediting.
		if ( ! Processed_Events::claim( $this->slug, 'adapter:' . $this->get_id(), 'mepr:txn:' . $txn_id ) ) {
			return;
		}

		$registry = $this->get_registry();
		$credits  = $registry->lookup_credits( $this->get_id(), $product_id );

		if ( $credits > 0 ) {
			$note = sprintf(
				/* translators: %d: MemberPress transaction ID. */
				__( 'Credits from MemberPress transaction #%d', 'wbcom-credits-sdk' ),
				$txn_id
			);

			\Wbcom\Credits\Credits::topup( $this->slug, $user_id, $credits, $note );
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
