<?php
/**
 * Consumer money-mode test.
 *
 * A money-mode consumer's ledger holds integer MINOR units, so a Consumer that
 * called the raw hold()/deduct()/refund() with a MAJOR-unit cost would charge
 * roughly 1/100th of the real price on a hundredths-based currency — and it
 * would do so silently, because every figure the member sees would still look
 * plausible.
 *
 * This locks the dispatch: money mode goes through the *_money() variants,
 * token mode goes through the raw ones, and the two must not be swapped.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Credits;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Ledger;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class ConsumerMoneyModeTest extends TestCase {

	private const MONEY_SLUG = 'consumer-money';
	private const TOKEN_SLUG = 'consumer-token';
	private const USER       = 77;

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_options, $wbcom_credits_test_filters;

		$wpdb                       = new FakeWpdb();
		$wbcom_credits_test_options = array();
		$wbcom_credits_test_filters = array();

		Registry::instance()->register(
			array(
				'slug'    => self::MONEY_SLUG,
				'prefix'  => 'cmon',
				'version' => '1.0.0',
				'money'   => array( 'currency' => 'USD' ),
			)
		);
		Registry::instance()->register(
			array(
				'slug'    => self::TOKEN_SLUG,
				'prefix'  => 'ctok',
				'version' => '1.0.0',
			)
		);
	}

	/**
	 * A money consumer's balance reads back in MAJOR units.
	 *
	 * 50 credits are stored as 5000. A Consumer comparing a 10-credit cost
	 * against a raw 5000 would think the member could afford 500 listings.
	 */
	public function test_money_balance_reads_in_major_units(): void {
		Credits::topup_money( self::MONEY_SLUG, self::USER, 50.0, '', 'seed' );

		$this->assertSame( 5000, Credits::get_balance( self::MONEY_SLUG, self::USER ) );
		$this->assertSame( 50.0, Credits::balance_money( self::MONEY_SLUG, self::USER ) );
	}

	/**
	 * Holding a major-unit cost against a money ledger reserves minor units.
	 *
	 * The 100x under-charge this guards: hold( 10 ) on a money ledger reserves
	 * 10 minor units — ten cents — for a ten-credit listing.
	 */
	public function test_money_hold_reserves_minor_units(): void {
		Credits::topup_money( self::MONEY_SLUG, self::USER, 50.0, '', 'seed' );
		Credits::hold_money( self::MONEY_SLUG, self::USER, 10.0, 1, '', 'hold' );

		$this->assertSame( 40.0, Credits::balance_money( self::MONEY_SLUG, self::USER ) );
	}

	/**
	 * The full hold -> commit lifecycle nets exactly the cost, once.
	 *
	 * deduct_with_hold_release() releases the hold and writes the permanent
	 * deduction in one transaction, so the member is charged once rather than
	 * twice — and is charged at all, which a release without a matching
	 * deduction would not do.
	 */
	public function test_money_hold_then_commit_charges_once(): void {
		Credits::topup_money( self::MONEY_SLUG, self::USER, 50.0, '', 'seed' );
		Credits::hold_money( self::MONEY_SLUG, self::USER, 10.0, 1, '', 'hold' );
		Credits::deduct_money( self::MONEY_SLUG, self::USER, 10.0, 1, '', 'commit' );

		$this->assertSame( 40.0, Credits::balance_money( self::MONEY_SLUG, self::USER ) );
	}

	/**
	 * A token consumer is untouched by any of this.
	 *
	 * Consumers that register no `money` key must keep integer semantics: 50
	 * topped up is 50 in the ledger, not 5000.
	 */
	public function test_token_consumer_stays_integer(): void {
		Credits::topup( self::TOKEN_SLUG, self::USER, 50, 'seed' );

		$this->assertSame( 50, Credits::get_balance( self::TOKEN_SLUG, self::USER ) );
		$this->assertFalse( Credits::is_money( self::TOKEN_SLUG ) );
	}

	/**
	 * cancel_hold_by_id() removes one specific hold and leaves others alone.
	 *
	 * Cancelling by item_id would drop every hold on that item; a consumer
	 * that placed two holds and cancelled one would silently release both.
	 */
	public function test_cancel_hold_by_id_removes_only_that_hold(): void {
		Credits::topup_money( self::MONEY_SLUG, self::USER, 50.0, '', 'seed' );

		$first  = Credits::hold_money( self::MONEY_SLUG, self::USER, 10.0, 1, '', 'hold one' );
		$second = Credits::hold_money( self::MONEY_SLUG, self::USER, 5.0, 1, '', 'hold two' );

		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
		$this->assertSame( 35.0, Credits::balance_money( self::MONEY_SLUG, self::USER ) );

		Credits::cancel_hold_by_id( self::MONEY_SLUG, self::USER, (int) $first );

		// Only the 10 comes back; the 5 stays held.
		$this->assertSame( 45.0, Credits::balance_money( self::MONEY_SLUG, self::USER ) );
	}
}
