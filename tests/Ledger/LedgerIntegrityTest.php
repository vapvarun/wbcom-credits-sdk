<?php
/**
 * SDK 1.9.0 ledger integrity: the findings in docs/AUDIT-2026-09-27.md.
 *
 * Holds are settled and released by id and never twice; a deduct with no
 * open hold charges nothing and says so; spends are checked under a lock;
 * every row says what happened; old tables gain the new columns; a claim
 * and its credit land together or not at all; reports read through the API.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Ledger;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Ledger;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class LedgerIntegrityTest extends TestCase {

	private const SLUG   = 'integrity';
	private const PREFIX = 'lint';
	private const USER   = 21;
	private const ITEM   = 900;

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_options, $wbcom_credits_test_filters, $wbcom_credits_test_hooks;

		$wpdb                       = new FakeWpdb();
		$wbcom_credits_test_options = array();
		$wbcom_credits_test_filters = array();
		$wbcom_credits_test_hooks   = array( 'actions' => array(), 'filters' => array() );

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);
		Ledger::maybe_create_table( self::PREFIX );
		\Wbcom\Credits\Gateways\Processed_Events::maybe_create_table( self::PREFIX );
		Credits::topup( self::SLUG, self::USER, 100, 'seed' );
	}

	private function rows(): array {
		global $wpdb;
		return array_values( $wpdb->tables[ Ledger::table_name( self::PREFIX ) ] );
	}

	private function balance(): int {
		Credits::invalidate_cache( self::SLUG, self::USER );
		return Credits::get_balance( self::SLUG, self::USER );
	}

	// Finding 1 ----------------------------------------------------------

	public function test_deduct_with_no_open_hold_charges_nothing_and_returns_false(): void {
		$this->assertFalse( Credits::deduct( self::SLUG, self::USER, 30, self::ITEM ) );
		$this->assertSame( 100, $this->balance() );
		$this->assertCount( 1, $this->rows(), 'No zero-sum rows are written.' );
	}

	public function test_deduct_settles_the_open_hold_once(): void {
		Credits::hold( self::SLUG, self::USER, 30, self::ITEM );

		$this->assertTrue( Credits::deduct( self::SLUG, self::USER, 30, self::ITEM ) );
		$this->assertFalse( Credits::deduct( self::SLUG, self::USER, 30, self::ITEM ), 'A second deduct finds no open hold.' );
		$this->assertSame( 70, $this->balance() );
	}

	public function test_settle_and_release_by_id_point_at_the_hold(): void {
		$hold = (int) Credits::hold( self::SLUG, self::USER, 30, self::ITEM );

		$spend = Credits::settle_hold( self::SLUG, self::USER, $hold );
		$this->assertIsInt( $spend );
		$this->assertFalse( Credits::settle_hold( self::SLUG, self::USER, $hold ), 'Settled once only.' );
		$this->assertFalse( Credits::release_hold( self::SLUG, self::USER, $hold ), 'A settled hold cannot be released.' );
		$this->assertSame( 70, $this->balance() );

		$closing = array_values( array_filter( $this->rows(), static fn ( $r ) => (int) ( $r['hold_id'] ?? 0 ) === $hold ) );
		$this->assertSame( array( 'hold_release', 'spend' ), array_column( $closing, 'reason' ) );
	}

	public function test_settle_can_spend_less_than_was_held(): void {
		$hold = (int) Credits::hold( self::SLUG, self::USER, 30, self::ITEM );
		Credits::settle_hold( self::SLUG, self::USER, $hold, 20 );

		$this->assertSame( 80, $this->balance() );
	}

	public function test_release_returns_the_hold(): void {
		$hold = (int) Credits::hold( self::SLUG, self::USER, 30, self::ITEM );

		$this->assertIsInt( Credits::release_hold( self::SLUG, self::USER, $hold ) );
		$this->assertSame( 100, $this->balance() );
		$this->assertFalse( Credits::release_hold( self::SLUG, self::USER, $hold ) );
	}

	public function test_another_users_hold_cannot_be_settled(): void {
		$hold = (int) Credits::hold( self::SLUG, self::USER, 30, self::ITEM );

		$this->assertFalse( Credits::settle_hold( self::SLUG, self::USER + 1, $hold ) );
	}

	// Finding 2 ----------------------------------------------------------

	public function test_try_hold_refuses_a_short_balance(): void {
		$this->assertFalse( Credits::try_hold( self::SLUG, self::USER, 101, self::ITEM ) );
		$this->assertIsInt( Credits::try_hold( self::SLUG, self::USER, 100, self::ITEM ) );
		$this->assertFalse( Credits::try_hold( self::SLUG, self::USER, 1, self::ITEM + 1 ), 'Nothing left to hold.' );
	}

	public function test_try_hold_reads_past_a_stale_cached_balance(): void {
		Credits::get_balance( self::SLUG, self::USER ); // Cached at 100.
		Ledger::insert( self::PREFIX, self::USER, 'deduction', -90, 0, 'another request', 'spend' );

		$this->assertFalse( Credits::try_hold( self::SLUG, self::USER, 50, self::ITEM ), 'The check must not trust the cache.' );
	}

	public function test_spend_charges_under_the_user_lock(): void {
		global $wpdb;

		$this->assertIsInt( Credits::spend( self::SLUG, self::USER, 40, self::ITEM, 'click' ) );
		$this->assertFalse( Credits::spend( self::SLUG, self::USER, 61, self::ITEM ) );
		$this->assertSame( 60, $this->balance() );
		$this->assertNotEmpty( $wpdb->locks_taken );
	}

	public function test_no_lock_means_no_charge(): void {
		global $wpdb;
		$wpdb->lock_result = '0';

		$this->assertFalse( Credits::spend( self::SLUG, self::USER, 10 ) );
		$this->assertFalse( Credits::try_hold( self::SLUG, self::USER, 10, self::ITEM ) );
		$this->assertSame( 100, $this->balance() );
	}

	// Finding 3 ----------------------------------------------------------

	public function test_every_write_records_a_reason(): void {
		Credits::adjust( self::SLUG, self::USER, -5, 'fix' );
		Credits::spend( self::SLUG, self::USER, 5 );
		$hold = (int) Credits::hold( self::SLUG, self::USER, 5, self::ITEM );
		Credits::release_hold( self::SLUG, self::USER, $hold );
		Credits::refund( self::SLUG, self::USER, 3, 0, 'goodwill' );

		$this->assertSame(
			array( 'topup', 'admin_adjust', 'spend', 'hold', 'hold_release', 'refund' ),
			array_column( $this->rows(), 'reason' )
		);
	}

	// Finding 5 ----------------------------------------------------------

	public function test_an_old_table_gains_the_new_columns_and_keys(): void {
		global $wpdb;
		$table = Ledger::table_name( 'old' );
		$wpdb->tables[ $table ]        = array();
		$wpdb->table_columns[ $table ] = array( 'id', 'user_id', 'item_id', 'entry_type', 'amount', 'note', 'created_at' );
		$wpdb->table_indexes[ $table ] = array( 'idx_user_id', 'idx_entry_type' );

		Ledger::maybe_create_table( 'old' );
		Ledger::maybe_create_table( 'old' ); // Idempotent.

		foreach ( array( 'expires_at', 'reason', 'reference', 'hold_id' ) as $col ) {
			$this->assertContains( $col, $wpdb->table_columns[ $table ] );
		}
		foreach ( array( 'idx_item_id', 'idx_user_item_type', 'idx_expiry', 'idx_user_created', 'idx_hold', 'idx_reason' ) as $key ) {
			$this->assertContains( $key, $wpdb->table_indexes[ $table ] );
		}
		$alters = array_filter( $wpdb->queries, static fn ( $q ) => str_contains( $q, 'ALTER TABLE' ) );
		$this->assertCount( 10, $alters, 'Each piece is added once.' );
	}

	// Finding 6 ----------------------------------------------------------

	public function test_cancel_hold_never_touches_a_settled_hold(): void {
		$first = (int) Credits::hold( self::SLUG, self::USER, 30, self::ITEM );
		Credits::settle_hold( self::SLUG, self::USER, $first );
		Credits::hold( self::SLUG, self::USER, 10, self::ITEM ); // A later attempt.

		Credits::cancel_hold( self::SLUG, self::USER, self::ITEM );

		$this->assertSame( 70, $this->balance(), 'The settled 30 stays charged; only the open 10 is cancelled.' );
	}

	public function test_cancel_hold_by_id_leaves_a_settled_hold_alone(): void {
		$hold = (int) Credits::hold( self::SLUG, self::USER, 30, self::ITEM );
		Credits::settle_hold( self::SLUG, self::USER, $hold );

		Credits::cancel_hold_by_id( self::SLUG, self::USER, $hold );

		$this->assertSame( 70, $this->balance() );
	}

	// Rows written before 1.9.0 ----------------------------------------

	public function test_legacy_rows_settled_the_old_way_are_not_open(): void {
		// 1.8: hold, then deduct() wrote an unlinked refund + deduction.
		Ledger::insert( self::PREFIX, self::USER, 'hold', -30, self::ITEM, 'Credits held' );
		Ledger::insert( self::PREFIX, self::USER, 'refund', 30, self::ITEM, 'Hold released on approval' );
		Ledger::insert( self::PREFIX, self::USER, 'deduction', -30, self::ITEM, 'Credits deducted' );

		$this->assertSame( array(), Ledger::open_holds( self::PREFIX, self::USER, self::ITEM ) );
		$this->assertFalse( Credits::deduct( self::SLUG, self::USER, 30, self::ITEM ) );
		$this->assertSame( 70, $this->balance() );
	}

	public function test_a_legacy_open_hold_settles_once(): void {
		Ledger::insert( self::PREFIX, self::USER, 'hold', -30, self::ITEM, 'Credits held' );

		$this->assertCount( 1, Ledger::open_holds( self::PREFIX, self::USER, self::ITEM ) );
		$this->assertTrue( Credits::deduct( self::SLUG, self::USER, 30, self::ITEM ) );
		$this->assertFalse( Credits::deduct( self::SLUG, self::USER, 30, self::ITEM ) );
		$this->assertSame( 70, $this->balance() );
	}

	// Finding 7 ----------------------------------------------------------

	public function test_topup_once_credits_a_payment_once(): void {
		$this->assertIsInt( Credits::topup_once( self::SLUG, 'adapter:woocommerce', 'woo:order:5', self::USER, 20, 'order 5' ) );
		$this->assertNull( Credits::topup_once( self::SLUG, 'adapter:woocommerce', 'woo:order:5', self::USER, 20, 'order 5' ) );
		$this->assertSame( 120, $this->balance() );

		$last = array_values( array_slice( $this->rows(), -1 ) )[0];
		$this->assertSame( 'purchase', $last['reason'] );
		$this->assertSame( 'woo:order:5', $last['reference'] );
	}

	public function test_a_rolled_back_transaction_keeps_neither_claim_nor_credit(): void {
		Ledger::begin();
		Credits::topup_once( self::SLUG, 'adapter:woocommerce', 'woo:order:6', self::USER, 20 );
		Ledger::rollback();

		$this->assertSame( 100, $this->balance() );
		$this->assertIsInt( Credits::topup_once( self::SLUG, 'adapter:woocommerce', 'woo:order:6', self::USER, 20 ), 'The retry credits.' );
	}

	public function test_nested_transactions_commit_once(): void {
		global $wpdb;
		$wpdb->queries = array();

		Ledger::begin();
		Ledger::begin();
		Ledger::commit();
		Ledger::commit();

		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $wpdb->queries );
	}

	// Finding 8 ----------------------------------------------------------

	public function test_query_ledger_filters_by_reason_user_and_date(): void {
		global $wpdb;
		Credits::spend( self::SLUG, self::USER, 10, 1 );
		Credits::spend( self::SLUG, self::USER, 15, 2 );
		Credits::topup( self::SLUG, self::USER + 1, 50, 'other user' );
		$table = Ledger::table_name( self::PREFIX );
		$wpdb->tables[ $table ][0]['created_at'] = '2026-01-01 00:00:00'; // The seed top-up is old.

		$spends = Credits::query_ledger( self::SLUG, array( 'user_id' => self::USER, 'reason' => 'spend' ) );
		$this->assertCount( 2, $spends );
		$this->assertSame( '2', $spends[0]->item_id, 'Newest first.' );

		$this->assertSame( -25, Credits::sum_ledger( self::SLUG, array( 'user_id' => self::USER, 'reason' => array( 'spend' ) ) ) );
		$this->assertSame( 3, Credits::count_ledger_rows( self::SLUG, array( 'user_id' => self::USER ) ) );
		$this->assertSame( 2, Credits::count_ledger_rows( self::SLUG, array( 'user_id' => self::USER, 'since' => '2026-06-01 00:00:00' ) ) );
		$this->assertSame( 1, Credits::count_ledger_rows( self::SLUG, array( 'until' => '2026-06-01 00:00:00' ) ) );
	}
}
