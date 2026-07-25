<?php
/**
 * Credits money-mode test.
 *
 * Locks the money-denominated convenience API added for
 * github.com/vapvarun/wbcom-credits-sdk#3: a `money` consumer converts
 * MAJOR-unit amounts to the ledger's integer MINOR units at one enforced
 * boundary via {@see Money}, so a consumer cannot lose cents (the `(int)`
 * truncation bug) or mix units across its entry points.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Credits;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Ledger;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class CreditsMoneyModeTest extends TestCase {

	private const SLUG_USD   = 'money-usd-plug';
	private const SLUG_CB    = 'money-cb-plug';
	private const SLUG_TOKEN = 'token-plug';
	private const USER       = 42;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$reg = new ReflectionProperty( Registry::class, 'instance' );
		$reg->setAccessible( true );
		$reg->setValue( null, null );

		$cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );

		// Money consumer with a static currency.
		Registry::instance()->register(
			array(
				'slug'    => self::SLUG_USD,
				'prefix'  => 'musd',
				'version' => '1.0.0',
				'money'   => array( 'currency' => 'USD' ),
			)
		);
		// Money consumer whose currency is resolved by a callable.
		Registry::instance()->register(
			array(
				'slug'    => self::SLUG_CB,
				'prefix'  => 'mcb',
				'version' => '1.0.0',
				'money'   => array( 'currency' => static fn (): string => 'KWD' ),
			)
		);
		// Plain token consumer — no money key.
		Registry::instance()->register(
			array(
				'slug'    => self::SLUG_TOKEN,
				'prefix'  => 'tok',
				'version' => '1.0.0',
			)
		);

		Ledger::maybe_create_table( 'musd' );
		Ledger::maybe_create_table( 'mcb' );
		Ledger::maybe_create_table( 'tok' );
	}

	public function test_is_money_reflects_registration(): void {
		self::assertTrue( Credits::is_money( self::SLUG_USD ) );
		self::assertTrue( Credits::is_money( self::SLUG_CB ) );
		self::assertFalse( Credits::is_money( self::SLUG_TOKEN ) );
	}

	public function test_topup_money_stores_minor_units_and_reads_back_major(): void {
		// 147.35 must store 14735 minor, not 147 (the (int) truncation bug).
		Credits::topup_money( self::SLUG_USD, self::USER, 147.35 );
		self::assertSame( 14735, Credits::get_balance( self::SLUG_USD, self::USER ) );
		self::assertSame( 147.35, Credits::balance_money( self::SLUG_USD, self::USER ) );
	}

	public function test_sub_unit_topup_is_not_lost(): void {
		// The confirmed Listora bug: (int) 0.5 == 0. Money mode must store 50.
		Credits::topup_money( self::SLUG_USD, self::USER, 0.5 );
		self::assertSame( 50, Credits::get_balance( self::SLUG_USD, self::USER ) );
		self::assertSame( 0.5, Credits::balance_money( self::SLUG_USD, self::USER ) );
	}

	public function test_zero_decimal_currency_via_explicit_override(): void {
		// JPY has no minor unit: 1255 -> 1255 (no x100).
		Credits::topup_money( self::SLUG_USD, self::USER, 1255, 'JPY' );
		self::assertSame( 1255, Credits::get_balance( self::SLUG_USD, self::USER ) );
		self::assertSame( 1255.0, Credits::balance_money( self::SLUG_USD, self::USER, 'JPY' ) );
	}

	public function test_three_decimal_currency_via_callable(): void {
		// KWD (3-decimal, resolved by the consumer's callable): 12.535 -> 12535.
		Credits::topup_money( self::SLUG_CB, self::USER, 12.535 );
		self::assertSame( 12535, Credits::get_balance( self::SLUG_CB, self::USER ) );
		self::assertSame( 12.535, Credits::balance_money( self::SLUG_CB, self::USER ) );
	}

	public function test_hold_deduct_refund_money_lifecycle_rounds_correctly(): void {
		// Full money lifecycle: topup -> hold -> deduct (commit) -> refund.
		Credits::topup_money( self::SLUG_USD, self::USER, 100.00 );
		Credits::hold_money( self::SLUG_USD, self::USER, 19.99, 7 );
		self::assertSame( 8001, Credits::get_balance( self::SLUG_USD, self::USER ) ); // 10000 - 1999 reserved.
		Credits::deduct_money( self::SLUG_USD, self::USER, 19.99, 7 );               // commit the hold.
		self::assertSame( 8001, Credits::get_balance( self::SLUG_USD, self::USER ) ); // net unchanged; spend is permanent.
		Credits::refund_money( self::SLUG_USD, self::USER, 19.99, 7 );
		self::assertSame( 10000, Credits::get_balance( self::SLUG_USD, self::USER ) );
	}

	public function test_adjust_money_preserves_sign(): void {
		Credits::topup_money( self::SLUG_USD, self::USER, 50.00 );
		Credits::adjust_money( self::SLUG_USD, self::USER, -12.34 );
		self::assertSame( 3766, Credits::get_balance( self::SLUG_USD, self::USER ) ); // 5000 - 1234.
	}
}
