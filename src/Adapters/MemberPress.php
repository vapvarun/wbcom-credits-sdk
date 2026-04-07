<?php
/**
 * MemberPress adapter — awards credits on completed transactions.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

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
	 * Extracts the transaction from the event, checks for double-processing
	 * against the SDK's ledger table, and tops up credits.
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

		// Double-processing check: query the SDK ledger table for a matching note.
		if ( $this->is_already_processed( $user_id, $txn_id ) ) {
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
	 * Check if a MemberPress transaction has already been processed.
	 *
	 * Queries the SDK ledger table for a topup entry referencing this transaction.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $txn_id  MemberPress transaction ID.
	 * @return bool True if already processed.
	 */
	private function is_already_processed( int $user_id, int $txn_id ): bool {
		global $wpdb;

		$table = \Wbcom\Credits\Ledger::table_name( $this->prefix );
		$note  = sprintf( 'MemberPress transaction #%d', $txn_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND entry_type = 'topup' AND note LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				'%' . $wpdb->esc_like( $note ) . '%'
			)
		);

		return (bool) $existing;
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
