<?php
/**
 * Pending_Checkouts enumeration tests - entries are found by option name, not
 * through a shared index that two simultaneous checkouts could corrupt.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Gateways\Pending_Checkouts;

final class PendingCheckoutsScanTest extends TestCase {

	private const SLUG = 'scan';

	protected function setUp(): void {
		Pending_Checkouts::reset_for_tests( self::SLUG );
		Pending_Checkouts::reset_for_tests( 'scan_other' );
	}

	private function put( string $session, int $user = 7, string $gateway = 'stripe' ): void {
		Pending_Checkouts::put(
			self::SLUG,
			$session,
			array(
				'gateway'     => $gateway,
				'user_id'     => $user,
				'credits'     => 10,
				'price_cents' => 500,
				'currency'    => 'usd',
			)
		);
	}

	public function test_put_writes_no_shared_option(): void {
		$this->put( 'cs_a' );

		self::assertSame( 'none', get_option( 'wbcom_credits_pc_scan_index', 'none' ), 'nothing is recorded in a shared option, so there is nothing to lose in a race' );
	}

	public function test_a_lost_index_row_no_longer_hides_a_checkout_from_the_reconcile_sweep(): void {
		$this->put( 'cs_a' );
		$this->put( 'cs_b' );

		// What the race did to the old index: one row gone, the other still there.
		update_option( 'wbcom_credits_pc_scan_index', array() );

		$sessions = array_column( Pending_Checkouts::oldest( self::SLUG, 10 ), 'session_id' );
		sort( $sessions );
		self::assertSame( array( 'cs_a', 'cs_b' ), $sessions );
	}

	public function test_for_user_and_coupon_holds_read_the_same_source(): void {
		Pending_Checkouts::stage_order( array( 'coupon' => 'HALF' ) );
		$this->put( 'cs_a', 7 );
		update_option( 'wbcom_credits_pc_scan_index', array() );

		self::assertCount( 1, Pending_Checkouts::for_user( self::SLUG, 7 ) );
		self::assertSame( 1, Pending_Checkouts::coupon_holds( self::SLUG, 'HALF', time() - 60 ) );
	}

	public function test_oldest_is_creation_order_and_respects_the_limit(): void {
		$this->put( 'cs_1' );
		$this->put( 'cs_2' );
		$this->put( 'cs_3' );

		$sessions = array_column( Pending_Checkouts::oldest( self::SLUG, 2 ), 'session_id' );
		self::assertSame( array( 'cs_1', 'cs_2' ), $sessions );
	}

	public function test_the_index_option_older_versions_left_behind_is_removed(): void {
		update_option( 'wbcom_credits_pc_scan_index', array( 'wbcom_credits_pc_scan_gone' => time() + 100 ) );
		$this->put( 'cs_a' );

		self::assertSame( 'none', get_option( 'wbcom_credits_pc_scan_index', 'none' ) );
		self::assertCount( 1, Pending_Checkouts::oldest( self::SLUG, 10 ), 'and it is never read as an entry' );
	}

	public function test_a_slug_that_shares_the_prefix_is_not_picked_up(): void {
		$this->put( 'cs_a' );
		Pending_Checkouts::put( 'scan_other', 'cs_x', array( 'gateway' => 'stripe', 'user_id' => 9, 'credits' => 1, 'price_cents' => 1, 'currency' => 'usd' ) );

		self::assertSame( array( 'cs_a' ), array_column( Pending_Checkouts::oldest( self::SLUG, 10 ), 'session_id' ) );
		self::assertSame( array( 'cs_x' ), array_column( Pending_Checkouts::oldest( 'scan_other', 10 ), 'session_id' ) );
		self::assertSame( array(), Pending_Checkouts::for_user( self::SLUG, 9 ) );
	}

	public function test_a_later_put_sweeps_an_expired_entry(): void {
		$this->put( 'cs_old' );
		$key   = 'wbcom_credits_pc_scan_' . md5( 'cs_old' );
		$entry = get_option( $key );
		$entry['expires_at'] = time() - 10;
		update_option( $key, $entry );

		$this->put( 'cs_new' );

		self::assertNull( get_option( $key, null ), 'the expired entry is deleted' );
		self::assertSame( array( 'cs_new' ), array_column( Pending_Checkouts::oldest( self::SLUG, 10 ), 'session_id' ) );
	}

	public function test_an_expired_entry_not_yet_swept_does_not_hide_a_live_one(): void {
		$this->put( 'cs_old' );
		$key   = 'wbcom_credits_pc_scan_' . md5( 'cs_old' );
		$entry = get_option( $key );
		$entry['expires_at'] = time() - 10;
		update_option( $key, $entry );
		// A live entry written after the expired one, with no put() (so no sweep) in between.
		update_option(
			'wbcom_credits_pc_scan_' . md5( 'cs_live' ),
			array(
				'session_id' => 'cs_live',
				'gateway'    => 'stripe',
				'user_id'    => 7,
				'expires_at' => time() + 1000,
			)
		);

		self::assertSame( array( 'cs_live' ), array_column( Pending_Checkouts::oldest( self::SLUG, 1 ), 'session_id' ), 'the expired entry is skipped, not counted against the limit' );
	}
}
