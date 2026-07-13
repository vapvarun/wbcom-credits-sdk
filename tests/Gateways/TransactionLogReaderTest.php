<?php
/**
 * Transaction_Log::list_transactions() + count_transactions() — the read side
 * a consuming plugin surfaces as an admin "Transactions" view.
 *
 * Locks: newest-first ordering, limit/offset pagination, kind + gateway +
 * user_id filtering, and cross-slug isolation (one consumer never sees
 * another's rows).
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\PayPal;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class TransactionLogReaderTest extends TestCase {

	private const SLUG   = 'reader-plug';
	private const PREFIX = 'rdr';

	private FakeWpdb $db;

	protected function setUp(): void {
		global $wpdb;
		$this->db = new FakeWpdb();
		$wpdb     = $this->db;

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		global $wbcom_credits_test_hooks, $wbcom_credits_test_options;
		$wbcom_credits_test_hooks   = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_options = array();

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);

		// A SECOND consumer sharing the wpdb, to prove slug isolation.
		Registry::instance()->register(
			array(
				'slug'    => 'other-plug',
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);

		Transaction_Log::maybe_create_table( self::PREFIX );
		$this->seed();
	}

	/**
	 * Seed: 3 Stripe checkouts + 1 PayPal checkout + 1 Stripe refund for our
	 * slug, plus 1 checkout for a different slug (same physical table).
	 */
	private function seed(): void {
		Transaction_Log::insert_checkout( array( 'slug' => self::SLUG, 'gateway' => Stripe::ID, 'session_id' => 'cs_1', 'event_id' => 'e1', 'user_id' => 5, 'credits' => 10, 'amount_cents' => 1000 ) );
		Transaction_Log::insert_checkout( array( 'slug' => self::SLUG, 'gateway' => Stripe::ID, 'session_id' => 'cs_2', 'event_id' => 'e2', 'user_id' => 6, 'credits' => 20, 'amount_cents' => 2000 ) );
		Transaction_Log::insert_checkout( array( 'slug' => self::SLUG, 'gateway' => PayPal::ID, 'session_id' => 'cs_3', 'event_id' => 'e3', 'user_id' => 5, 'credits' => 50, 'amount_cents' => 5000 ) );
		Transaction_Log::insert_refund( array( 'slug' => self::SLUG, 'gateway' => Stripe::ID, 'session_id' => 'cs_1', 'event_id' => 'r1', 'user_id' => 5, 'credits' => -10, 'amount_cents' => -1000, 'parent_id' => 1 ) );
		Transaction_Log::insert_checkout( array( 'slug' => 'other-plug', 'gateway' => Stripe::ID, 'session_id' => 'cs_x', 'event_id' => 'ex', 'user_id' => 99, 'credits' => 1, 'amount_cents' => 100 ) );
	}

	public function test_count_is_scoped_to_the_slug(): void {
		self::assertSame( 4, Transaction_Log::count_transactions( self::SLUG ) );
		self::assertSame( 1, Transaction_Log::count_transactions( 'other-plug' ) );
	}

	public function test_list_returns_only_this_slug_newest_first(): void {
		$rows = Transaction_Log::list_transactions( self::SLUG, array( 'limit' => 10 ) );

		self::assertCount( 4, $rows );
		foreach ( $rows as $row ) {
			self::assertSame( self::SLUG, $row['slug'], 'A row from another consumer leaked into the list.' );
		}
		// Newest id (the refund, inserted 4th of our rows) comes first.
		self::assertSame( 'r1', (string) $rows[0]['event_id'] );
	}

	public function test_limit_and_offset_paginate(): void {
		$page1 = Transaction_Log::list_transactions( self::SLUG, array( 'limit' => 2, 'offset' => 0 ) );
		$page2 = Transaction_Log::list_transactions( self::SLUG, array( 'limit' => 2, 'offset' => 2 ) );

		self::assertCount( 2, $page1 );
		self::assertCount( 2, $page2 );
		$ids = array_merge(
			array_map( static fn ( $r ) => (int) $r['id'], $page1 ),
			array_map( static fn ( $r ) => (int) $r['id'], $page2 )
		);
		self::assertSame( $ids, array_unique( $ids ), 'Pages overlapped — offset is wrong.' );
	}

	public function test_filter_by_kind(): void {
		$refunds = Transaction_Log::list_transactions( self::SLUG, array( 'kind' => Transaction_Log::KIND_REFUND ) );
		self::assertCount( 1, $refunds );
		self::assertSame( 'r1', (string) $refunds[0]['event_id'] );
		self::assertSame( 1, Transaction_Log::count_transactions( self::SLUG, array( 'kind' => Transaction_Log::KIND_REFUND ) ) );
		self::assertSame( 3, Transaction_Log::count_transactions( self::SLUG, array( 'kind' => Transaction_Log::KIND_CHECKOUT ) ) );
	}

	public function test_filter_by_gateway(): void {
		$paypal = Transaction_Log::list_transactions( self::SLUG, array( 'gateway' => PayPal::ID ) );
		self::assertCount( 1, $paypal );
		self::assertSame( 'cs_3', (string) $paypal[0]['session_id'] );
	}

	public function test_filter_by_user(): void {
		self::assertSame( 3, Transaction_Log::count_transactions( self::SLUG, array( 'user_id' => 5 ) ) );
		self::assertSame( 1, Transaction_Log::count_transactions( self::SLUG, array( 'user_id' => 6 ) ) );
	}
}
