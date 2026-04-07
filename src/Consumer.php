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

		$user_id = (int) $post->post_author;
		$cost    = $this->resolve_cost( $item_id );

		if ( $cost <= 0 ) {
			return;
		}

		$balance = Credits::get_balance( $this->slug, $user_id );
		if ( $balance < $cost ) {
			return;
		}

		Credits::hold( $this->slug, $user_id, $cost, $item_id, $this->config['label'] . ' — credits held' );
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
		$cost    = $this->resolve_cost( $item_id );

		if ( $cost <= 0 ) {
			return;
		}

		Credits::deduct( $this->slug, $user_id, $cost, $item_id, $this->config['label'] . ' — credits deducted' );
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
		$cost    = $this->resolve_cost( $item_id );

		if ( $cost <= 0 ) {
			return;
		}

		Credits::refund( $this->slug, $user_id, $cost, $item_id, $this->config['label'] . ' — credits refunded' );
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
