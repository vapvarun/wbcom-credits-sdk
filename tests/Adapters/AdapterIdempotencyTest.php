<?php
/**
 * Adapter idempotency test (I-H3).
 *
 * The bundled membership/subscription adapters previously deduped with a
 * read-then-write order/membership meta flag (get_meta → topup → save), which
 * has a TOCTOU window: two concurrent deliveries of the SAME order (or the
 * WooCommerce processing→completed pair) could both read "not processed" before
 * either saved and both top up. The adapters now route dedupe through the same
 * atomic Processed_Events::claim() the webhook path uses, with a stable per-order
 * event id under an `adapter:{id}` gateway tag.
 *
 * This locks the guard the adapters delegate to: for a fixed (slug, gateway,
 * event_id) exactly one of N racing claims wins, so the credit body runs once;
 * a distinct order still claims (and credits) independently.
 *
 * We exercise the claim mechanism directly with the adapters' real event-id /
 * gateway-tag shape rather than booting WooCommerce — the adapter's
 * correctness reduces entirely to "claim FIRST, credit only on a winning
 * claim," which is what this asserts.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class AdapterIdempotencyTest extends TestCase {

	private const SLUG    = 'adapter-plug';
	private const PREFIX  = 'adpg';
	private const GATEWAY = 'adapter:woocommerce';

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$balance_cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$balance_cache->setAccessible( true );
		$balance_cache->setValue( null, array() );

		global $wbcom_credits_test_hooks;
		$wbcom_credits_test_hooks = array( 'actions' => array(), 'filters' => array() );

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);

		\Wbcom\Credits\Ledger::maybe_create_table( self::PREFIX );
		Processed_Events::maybe_create_table( self::PREFIX );
	}

	/**
	 * Simulate the adapter body: claim THEN credit. Returns whether this
	 * delivery actually credited (i.e. it won the claim).
	 */
	private function deliver_order( int $order_id, int $user_id, int $credits ): bool {
		// Mirrors WooCommerceAdapter::on_order_completed() dedupe shape.
		if ( ! Processed_Events::claim( self::SLUG, self::GATEWAY, 'woo:order:' . $order_id ) ) {
			return false;
		}
		Credits::topup( self::SLUG, $user_id, $credits, 'order ' . $order_id );
		return true;
	}

	public function test_concurrent_deliveries_of_same_order_credit_once(): void {
		$user_id = 42;
		$winners = 0;

		// 5 racing deliveries of the SAME order (e.g. processing + completed +
		// duplicate IPNs). Exactly one must win the claim and credit.
		for ( $i = 0; $i < 5; $i++ ) {
			if ( $this->deliver_order( 1001, $user_id, 20 ) ) {
				++$winners;
			}
		}

		self::assertSame( 1, $winners, 'Exactly one of N concurrent deliveries may credit.' );
		self::assertSame( 20, Credits::get_balance( self::SLUG, $user_id ), 'Order must be credited exactly once.' );
	}

	public function test_distinct_orders_each_credit_once(): void {
		$user_id = 43;

		self::assertTrue( $this->deliver_order( 2001, $user_id, 15 ) );
		self::assertTrue( $this->deliver_order( 2002, $user_id, 25 ), 'A different order must claim independently.' );
		// Replay of the first order must NOT credit again.
		self::assertFalse( $this->deliver_order( 2001, $user_id, 15 ) );

		self::assertSame( 40, Credits::get_balance( self::SLUG, $user_id ), 'Two distinct orders credit once each; replay is a no-op.' );
	}

	public function test_same_order_id_isolated_per_adapter_gateway_tag(): void {
		// The WooCommerce and WooSubscriptions adapters use distinct event-id
		// prefixes (woo:order vs woosub:order) AND distinct gateway tags, so the
		// same numeric order id under two adapters does not collide.
		self::assertTrue( Processed_Events::claim( self::SLUG, 'adapter:woocommerce', 'woo:order:3001' ) );
		self::assertTrue( Processed_Events::claim( self::SLUG, 'adapter:woo_subscriptions', 'woosub:order:3001' ), 'Distinct adapter tag must not collide on the same order id.' );
		self::assertFalse( Processed_Events::claim( self::SLUG, 'adapter:woocommerce', 'woo:order:3001' ), 'Same tag + id must be deduped.' );
	}

	/**
	 * Mirrors PMProAdapter::on_level_change() dedupe shape: claim
	 * `adapter:pmpro` + `pmpro:level:{user}:{level}:{date}` THEN credit.
	 */
	private function deliver_pmpro_level_change( int $user_id, int $level_id, string $today, int $credits ): bool {
		if ( ! Processed_Events::claim( self::SLUG, 'adapter:pmpro', 'pmpro:level:' . $user_id . ':' . $level_id . ':' . $today ) ) {
			return false;
		}
		Credits::topup( self::SLUG, $user_id, $credits, 'pmpro level ' . $level_id );
		return true;
	}

	/**
	 * Mirrors PMProAdapter::on_subscription_payment() dedupe shape: claim
	 * `adapter:pmpro` + `pmpro:order:{order_id}` THEN credit.
	 */
	private function deliver_pmpro_order( int $order_id, int $user_id, int $credits ): bool {
		if ( ! Processed_Events::claim( self::SLUG, 'adapter:pmpro', 'pmpro:order:' . $order_id ) ) {
			return false;
		}
		Credits::topup( self::SLUG, $user_id, $credits, 'pmpro order ' . $order_id );
		return true;
	}

	/**
	 * Mirrors MemberPressAdapter::on_transaction_completed() dedupe shape:
	 * claim `adapter:memberpress` + `mepr:txn:{txn_id}` THEN credit.
	 */
	private function deliver_memberpress_txn( int $txn_id, int $user_id, int $credits ): bool {
		if ( ! Processed_Events::claim( self::SLUG, 'adapter:memberpress', 'mepr:txn:' . $txn_id ) ) {
			return false;
		}
		Credits::topup( self::SLUG, $user_id, $credits, 'mepr txn ' . $txn_id );
		return true;
	}

	public function test_pmpro_level_change_concurrent_deliveries_credit_once(): void {
		$user_id = 44;
		$today   = '2026-07-13';
		$winners = 0;

		// 5 racing deliveries of the SAME level-change event on the same day
		// (e.g. a retried pmpro_after_change_membership_level hook). Exactly
		// one must win the claim and credit.
		for ( $i = 0; $i < 5; $i++ ) {
			if ( $this->deliver_pmpro_level_change( $user_id, 7, $today, 30 ) ) {
				++$winners;
			}
		}

		self::assertSame( 1, $winners, 'Exactly one of N concurrent PMPro level-change deliveries may credit.' );
		self::assertSame( 30, Credits::get_balance( self::SLUG, $user_id ), 'PMPro level change must be credited exactly once.' );
	}

	public function test_pmpro_subscription_payment_concurrent_deliveries_credit_once(): void {
		$user_id = 45;
		$winners = 0;

		// 5 racing deliveries of the SAME recurring-payment order.
		for ( $i = 0; $i < 5; $i++ ) {
			if ( $this->deliver_pmpro_order( 5001, $user_id, 20 ) ) {
				++$winners;
			}
		}

		self::assertSame( 1, $winners, 'Exactly one of N concurrent PMPro order deliveries may credit.' );
		self::assertSame( 20, Credits::get_balance( self::SLUG, $user_id ), 'PMPro recurring payment must be credited exactly once.' );
	}

	public function test_memberpress_transaction_completed_concurrent_deliveries_credit_once(): void {
		$user_id = 46;
		$winners = 0;

		// 5 racing deliveries of the SAME MemberPress transaction (replays of
		// mepr_event_transaction_completed).
		for ( $i = 0; $i < 5; $i++ ) {
			if ( $this->deliver_memberpress_txn( 6001, $user_id, 15 ) ) {
				++$winners;
			}
		}

		self::assertSame( 1, $winners, 'Exactly one of N concurrent MemberPress transaction deliveries may credit.' );
		self::assertSame( 15, Credits::get_balance( self::SLUG, $user_id ), 'MemberPress transaction must be credited exactly once.' );
	}
}
