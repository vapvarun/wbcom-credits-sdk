<?php
/**
 * Consumer — auto-wires hold/deduct/refund hooks for a credit-consuming action.
 *
 * @package Wbcom\Credits
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Represents something a plugin "sells" that costs credits.
 *
 * Each consumer auto-wires three WordPress action hooks:
 * - hold_on:   reserves credits when item is submitted
 * - deduct_on: settles (permanently deducts) when item is approved
 * - refund_on: releases hold when item is rejected
 *
 * @since 1.0.0
 */
final class Consumer {

	/**
	 * Plugin slug this consumer belongs to.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Plugin DB prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Consumer configuration.
	 *
	 * @var array{id: string, label: string, cost: int|callable, hold_on: string, deduct_on: string, refund_on: string}
	 */
	private array $config;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $prefix Plugin DB prefix.
	 * @param array  $config Consumer configuration.
	 */
	public function __construct( string $slug, string $prefix, array $config ) {
		$this->slug   = $slug;
		$this->prefix = $prefix;
		$this->config = wp_parse_args(
			$config,
			array(
				'id'        => '',
				'label'     => '',
				'cost'      => 0,
				'hold_on'   => '',
				'deduct_on' => '',
				'refund_on' => '',
			)
		);
	}

	/**
	 * Register WordPress action hooks for the hold/deduct/refund lifecycle.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! empty( $this->config['hold_on'] ) ) {
			add_action( $this->config['hold_on'], array( $this, 'on_hold' ), 10, 1 );
		}

		if ( ! empty( $this->config['deduct_on'] ) ) {
			add_action( $this->config['deduct_on'], array( $this, 'on_deduct' ), 10, 1 );
		}

		if ( ! empty( $this->config['refund_on'] ) ) {
			add_action( $this->config['refund_on'], array( $this, 'on_refund' ), 10, 1 );
		}
	}

	/**
	 * Handle the hold event — reserve credits when item is submitted.
	 *
	 * Expects the action to pass the item (post) ID as first argument.
	 *
	 * @since 1.0.0
	 *
	 * @param int $item_id Post/item ID.
	 * @return void
	 */
	public function on_hold( int $item_id ): void {
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Held or settled already: a second hold event (a resubmission, a
		// retried request) must not reserve again.
		if ( in_array( $this->state( $item_id )['state'], array( 'held', 'settled' ), true ) ) {
			return;
		}

		$user_id = (int) $post->post_author;
		$cost    = $this->resolve_cost( $item_id );

		if ( $cost <= 0 ) {
			return;
		}

		$balance = $this->current_balance( $user_id );
		if ( $balance < $cost ) {
			return;
		}

		if ( $this->reserve( $user_id, $cost, $item_id, $this->config['label'] . ' — credits held' ) ) {
			$this->set_state( $item_id, 'held', $cost );
		}
	}

	/**
	 * This consumer's record for an item: state and the amount it holds.
	 *
	 * States: '' (nothing recorded - items from before 1.7.2 behave as
	 * they always did), 'held', 'settled', 'released'.
	 *
	 * @param int $item_id Post/item ID.
	 * @return array{state: string, cost: int}
	 */
	private function state( int $item_id ): array {
		$raw = get_post_meta( $item_id, $this->state_key(), true );
		return array(
			'state' => is_array( $raw ) ? (string) ( $raw['state'] ?? '' ) : '',
			'cost'  => is_array( $raw ) ? (int) ( $raw['cost'] ?? 0 ) : 0,
		);
	}

	/**
	 * Record an item's state.
	 *
	 * @param int    $item_id Post/item ID.
	 * @param string $state   held | settled | released.
	 * @param int    $cost    Amount held, in resolve_cost() units.
	 * @return void
	 */
	private function set_state( int $item_id, string $state, int $cost ): void {
		update_post_meta( $item_id, $this->state_key(), array( 'state' => $state, 'cost' => $cost ) );
	}

	/**
	 * Post meta key for this consumer's record.
	 *
	 * @return string
	 */
	private function state_key(): string {
		return '_wbcom_credits_' . sanitize_key( $this->slug ) . '_' . sanitize_key( (string) $this->config['id'] );
	}

	/**
	 * Handle the deduct event — permanently deduct credits on approval.
	 *
	 * @since 1.0.0
	 *
	 * @param int $item_id Post/item ID.
	 * @return void
	 */
	public function on_deduct( int $item_id ): void {
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$user_id = (int) $post->post_author;
		$record  = $this->state( $item_id );

		// Recorded items settle only an open hold, for the amount held. A
		// republish of a settled item (renewal, reactivation) or an item
		// whose hold was never placed charges nothing more.
		if ( '' !== $record['state'] ) {
			if ( 'held' === $record['state'] && $record['cost'] > 0 ) {
				$this->settle( $user_id, $record['cost'], $item_id, $this->config['label'] . ' — credits deducted' );
				$this->set_state( $item_id, 'settled', $record['cost'] );
			}
			return;
		}

		$cost = $this->resolve_cost( $item_id );

		if ( $cost <= 0 ) {
			return;
		}

		$this->settle( $user_id, $cost, $item_id, $this->config['label'] . ' — credits deducted' );
	}

	/**
	 * Handle the refund event — release held credits on rejection.
	 *
	 * @since 1.0.0
	 *
	 * @param int $item_id Post/item ID.
	 * @return void
	 */
	public function on_refund( int $item_id ): void {
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$user_id = (int) $post->post_author;
		$record  = $this->state( $item_id );

		// Recorded items release only an open hold. Deactivating or trashing
		// an item whose credits were already settled used to refund them,
		// so a member could take a paid listing down, get paid back and put
		// it up again for free.
		if ( '' !== $record['state'] ) {
			if ( 'held' === $record['state'] && $record['cost'] > 0 ) {
				$this->release( $user_id, $record['cost'], $item_id, $this->config['label'] . ' — credits refunded' );
				$this->set_state( $item_id, 'released', $record['cost'] );
			}
			return;
		}

		$cost = $this->resolve_cost( $item_id );

		if ( $cost <= 0 ) {
			return;
		}

		$this->release( $user_id, $cost, $item_id, $this->config['label'] . ' — credits refunded' );
	}

	/**
	 * Read the user's balance in the same units resolve_cost() returns.
	 *
	 * When the slug is registered in money mode the SDK ledger stores integer
	 * MINOR units, so the raw Credits::get_balance()/hold()/deduct()/refund()
	 * methods would treat a MAJOR-unit cost (e.g. a 5-credit listing fee) as 5
	 * minor units — a ~100x under-charge on a hundredths-based currency. Dispatch
	 * to the *_money() variants (which convert major→minor) whenever the consumer
	 * runs in money mode, and to the raw methods otherwise.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return float Balance in major units when money mode, else raw credits.
	 */
	private function current_balance( int $user_id ): float {
		return Credits::is_money( $this->slug )
			? Credits::balance_money( $this->slug, $user_id )
			: (float) Credits::get_balance( $this->slug, $user_id );
	}

	/**
	 * Reserve credits for the cost, money-mode aware.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $cost    Cost in the unit resolve_cost() returns (major credits).
	 * @param int    $item_id Item ID.
	 * @param string $note    Ledger note.
	 * @return bool Whether the hold was written.
	 */
	private function reserve( int $user_id, int $cost, int $item_id, string $note ): bool {
		if ( Credits::is_money( $this->slug ) ) {
			return false !== Credits::hold_money( $this->slug, $user_id, (float) $cost, $item_id, '', $note );
		}
		return false !== Credits::hold( $this->slug, $user_id, $cost, $item_id, $note );
	}

	/**
	 * Permanently deduct credits for the cost, money-mode aware.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $cost    Cost in the unit resolve_cost() returns (major credits).
	 * @param int    $item_id Item ID.
	 * @param string $note    Ledger note.
	 * @return void
	 */
	private function settle( int $user_id, int $cost, int $item_id, string $note ): void {
		if ( Credits::is_money( $this->slug ) ) {
			Credits::deduct_money( $this->slug, $user_id, (float) $cost, $item_id, '', $note );
			return;
		}
		Credits::deduct( $this->slug, $user_id, $cost, $item_id, $note );
	}

	/**
	 * Refund/release credits for the cost, money-mode aware.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $cost    Cost in the unit resolve_cost() returns (major credits).
	 * @param int    $item_id Item ID.
	 * @param string $note    Ledger note.
	 * @return void
	 */
	private function release( int $user_id, int $cost, int $item_id, string $note ): void {
		if ( Credits::is_money( $this->slug ) ) {
			Credits::refund_money( $this->slug, $user_id, (float) $cost, $item_id, '', $note );
			return;
		}
		Credits::refund( $this->slug, $user_id, $cost, $item_id, $note );
	}

	/**
	 * Resolve the credit cost — supports fixed int or callable.
	 *
	 * @since 1.0.0
	 *
	 * @param int $item_id Item ID for dynamic cost lookups.
	 * @return int Credit cost.
	 */
	private function resolve_cost( int $item_id ): int {
		$cost = $this->config['cost'];

		if ( is_callable( $cost ) ) {
			return (int) call_user_func( $cost, $item_id );
		}

		return (int) $cost;
	}
}
