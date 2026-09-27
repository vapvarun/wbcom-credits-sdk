<?php
/**
 * Balance events: the low-balance alert fires once per crossing, and admin
 * adjustments announce themselves.
 *
 * The alert fired on every hold at or below the threshold, so a member
 * posting several items in a row got an email per post.
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

final class BalanceEventsTest extends TestCase {

	private const SLUG = 'balance-events';
	private const USER = 7;

	/** @var array<int, array<int, mixed>> */
	private array $low = array();

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_options, $wbcom_credits_test_filters, $wbcom_credits_test_usermeta;

		$wpdb                         = new FakeWpdb();
		$wbcom_credits_test_options   = array();
		$wbcom_credits_test_filters   = array();
		$wbcom_credits_test_usermeta  = array();
		$this->low                    = array();

		Registry::instance()->register(
			array(
				'slug'     => self::SLUG,
				'prefix'   => 'bev',
				'version'  => '1.0.0',
				'settings' => array( 'low_threshold' => 5 ),
			)
		);
		Ledger::maybe_create_table( 'bev' );

		add_action(
			'wbcom_credits_low',
			function ( ...$args ): void {
				if ( self::SLUG === $args[0] ) {
					$this->low[] = $args;
				}
			},
			10,
			3
		);
	}

	public function test_low_balance_fires_once_per_crossing(): void {
		Credits::topup( self::SLUG, self::USER, 10 );
		Credits::hold( self::SLUG, self::USER, 6, 1 ); // 4 left: crosses.
		Credits::hold( self::SLUG, self::USER, 1, 2 ); // 3: still below.
		Credits::hold( self::SLUG, self::USER, 1, 3 ); // 2: still below.
		$this->assertCount( 1, $this->low );

		Credits::topup( self::SLUG, self::USER, 10 );  // 12: back above.
		Credits::hold( self::SLUG, self::USER, 8, 4 ); // 4: crosses again.
		$this->assertCount( 2, $this->low );
	}

	public function test_an_admin_deduction_can_cross_the_threshold(): void {
		Credits::topup( self::SLUG, self::USER, 10 );
		Credits::adjust( self::SLUG, self::USER, -7, 'correction' );

		$this->assertCount( 1, $this->low );
	}

	public function test_adjust_fires_an_event_with_the_signed_amount(): void {
		$seen = array();
		add_action(
			'wbcom_credits_adjusted',
			static function ( ...$args ) use ( &$seen ): void {
				$seen[] = $args;
			},
			10,
			4
		);

		Credits::adjust( self::SLUG, self::USER, -3, 'correction' );

		$this->assertSame( array( array( self::SLUG, self::USER, -3, 'correction' ) ), $seen );
	}
}
