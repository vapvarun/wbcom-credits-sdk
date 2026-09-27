<?php
/**
 * SDK 1.9.2: the fixes from the 2026-09-27 review round (Wbcom Credits SDK
 * board). Actions wait for the commit; locks taken in a transaction are held
 * to its end; refunds, coupons and free checkouts are atomic; consumers get
 * credit(), the ledger id on topped_up, grouped totals and one-row reads.
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
use Wbcom\Credits\Gateways\Coupons;
use Wbcom\Credits\Gateways\Pending_Checkouts;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Gateways\Webhook_Controller;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class Release192Test extends TestCase {

	private const SLUG   = 'rel192';
	private const PREFIX = 'r192';
	private const USER   = 31;

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_options, $wbcom_credits_test_hooks, $wbcom_credits_test_uid;

		$wpdb                       = new FakeWpdb();
		$wbcom_credits_test_options = array();
		$wbcom_credits_test_hooks   = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_uid     = self::USER;

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
				'pricing' => array(
					'currency' => 'USD',
					'packs'    => array( 'p10' => array( 'credits' => 10, 'price_cents' => 1000 ) ),
				),
			)
		);
		Ledger::maybe_create_table( self::PREFIX );
		Transaction_Log::maybe_create_table( self::PREFIX );
		Processed_Events::maybe_create_table( self::PREFIX );
	}

	private function fired( string $hook ): array {
		global $wbcom_credits_test_hooks;
		$seen = array();
		add_action(
			$hook,
			static function ( ...$args ) use ( &$seen ) {
				$seen[] = $args;
			},
			10,
			6
		);
		return array( &$seen );
	}

	// Card 5: actions wait for the commit -------------------------------

	public function test_topped_up_waits_for_the_commit_and_is_dropped_on_rollback(): void {
		$box  = $this->fired( 'wbcom_credits_topped_up' );
		$seen = &$box[0];

		Ledger::begin();
		Credits::topup( self::SLUG, self::USER, 5, 'inside' );
		$this->assertSame( array(), $seen, 'Not while the transaction is open.' );
		Ledger::commit();
		$this->assertCount( 1, $seen );

		Ledger::begin();
		Credits::topup( self::SLUG, self::USER, 5, 'rolled back' );
		Ledger::rollback();
		$this->assertCount( 1, $seen, 'A rolled-back top-up never announces itself.' );
	}

	// Card 7: ledger id on topped_up --------------------------------------

	public function test_topped_up_passes_the_ledger_row_id(): void {
		$box  = $this->fired( 'wbcom_credits_topped_up' );
		$seen = &$box[0];

		$id = Credits::topup( self::SLUG, self::USER, 5, 'note' );

		$this->assertSame( array( self::SLUG, self::USER, 5, 'note', $id ), $seen[0] );
	}

	// Card 6: credit() ----------------------------------------------------

	public function test_credit_is_item_linked_and_not_a_purchase(): void {
		$topped   = $this->fired( 'wbcom_credits_topped_up' );
		$credited = $this->fired( 'wbcom_credits_credited' );

		$id  = Credits::credit( self::SLUG, self::USER, 250, 77, 'Refund: ad 77', 'refund', 'ad:77' );
		$row = Credits::get_ledger_row( self::SLUG, (int) $id );

		$this->assertSame( array(), $topped[0], 'No "funds added" event.' );
		$this->assertSame( array( self::SLUG, self::USER, 250, 77, $id, 'refund' ), $credited[0][0] );
		$this->assertSame( 'topup', $row->entry_type );
		$this->assertSame( '77', $row->item_id );
		$this->assertSame( 'refund', $row->reason );
		$this->assertSame( 250, Credits::get_balance( self::SLUG, self::USER ) );
		$this->assertNull( Credits::get_ledger_row( self::SLUG, 999999 ) );
	}

	// Card 8: grouped totals ------------------------------------------------

	public function test_grouped_totals_by_reason_and_for_many_users(): void {
		Credits::topup( self::SLUG, 1, 100, 'a' );
		Credits::topup( self::SLUG, 2, 50, 'b' );
		Credits::spend( self::SLUG, 1, 30 );
		Credits::topup( self::SLUG, 3, 999, 'not asked for' );

		$by_reason = Credits::sum_ledger_grouped( self::SLUG, array( 'user_ids' => array( 1, 2 ) ) );
		$this->assertSame( array( 'total' => 150, 'count' => 2 ), $by_reason['topup'] );
		$this->assertSame( array( 'total' => -30, 'count' => 1 ), $by_reason['spend'] );

		$by_user = Credits::sum_ledger_grouped( self::SLUG, array( 'user_ids' => array( 1, 2 ) ), 'user_id' );
		$this->assertSame( 70, $by_user['1']['total'] );
		$this->assertSame( 50, $by_user['2']['total'] );
		$this->assertArrayNotHasKey( '3', $by_user );
	}

	// Later card: settle never charges more than was held ------------------

	public function test_settle_refuses_more_than_was_held(): void {
		Credits::topup( self::SLUG, self::USER, 100, 'seed' );
		$hold = (int) Credits::hold( self::SLUG, self::USER, 30, 5 );

		$this->assertFalse( Credits::settle_hold( self::SLUG, self::USER, $hold, 40 ) );
		$this->assertIsInt( Credits::settle_hold( self::SLUG, self::USER, $hold, 20 ) );
		$this->assertSame( 80, Credits::get_balance( self::SLUG, self::USER ) );
	}

	// Card 2: a lock taken in a transaction is held to its end -------------

	public function test_a_lock_taken_inside_a_transaction_is_released_at_commit(): void {
		global $wpdb;

		Ledger::begin();
		Credits::with_user_lock( self::SLUG, self::USER, static fn () => true );
		$this->assertTrue( Ledger::in_user_lock( self::PREFIX, self::USER ), 'Still held after the callback.' );
		Ledger::commit();
		$this->assertFalse( Ledger::in_user_lock( self::PREFIX, self::USER ) );

		$releases = array_filter( $wpdb->reads, static fn ( $q ) => false !== strpos( $q, 'RELEASE_LOCK' ) );
		$this->assertCount( 1, $releases );
	}

	// Cards 1 and 4: coupons and the free checkout -------------------------

	private function checkout( string $coupon ) {
		$request = new \WP_REST_Request();
		$request->set_param( 'gateway', Stripe::ID );
		$request->set_param( 'pack_id', 'p10' );
		$request->set_param( 'coupon', $coupon );
		$request->set_param(
			'billing',
			array(
				'billing_first_name' => 'A',
				'billing_last_name'  => 'B',
				'billing_email'      => 'a@b.test',
				'billing_country'    => 'US',
			)
		);
		return ( new Webhook_Controller( self::SLUG ) )->create_checkout( $request );
	}

	private function coupons( int $limit ): void {
		update_option( 'wbcom_credits_gateway_settings_' . self::SLUG, array( Stripe::ID => array( 'enabled' => '1', 'mode' => 'test', 'secret_key_test' => 'sk_test_stub', 'publishable_key_test' => 'pk_test_stub' ) ) );
		update_option( Coupons::option_name( self::SLUG ), array( 'FREE' => array( 'type' => 'percent', 'amount' => 100, 'expires' => '', 'usage_limit' => $limit, 'active' => true ) ) );
	}

	public function test_a_limited_free_coupon_credits_once(): void {
		$this->coupons( 1 );

		$first  = $this->checkout( 'FREE' );
		$second = $this->checkout( 'FREE' );

		$this->assertInstanceOf( \WP_REST_Response::class, $first );
		$this->assertTrue( $first->get_data()['free'] );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'coupon_used_up', $second->get_error_code() );
		$this->assertSame( 10, Credits::get_balance( self::SLUG, self::USER ) );
	}

	public function test_an_unpaid_checkout_holds_a_limited_coupon_for_an_hour(): void {
		update_option( Coupons::option_name( self::SLUG ), array( 'HALF' => array( 'type' => 'percent', 'amount' => 50, 'expires' => '', 'usage_limit' => 1, 'active' => true ) ) );

		Pending_Checkouts::stage_order( array( 'coupon' => 'HALF' ) );
		Pending_Checkouts::put( self::SLUG, 'cs_hold_1', array( 'user_id' => 9, 'credits' => 10, 'price_cents' => 500 ) );
		$this->assertSame( 1, Coupons::usage( self::SLUG, 'HALF' ) );
		$this->assertSame( 'coupon_used_up', Coupons::find( self::SLUG, 'HALF' )->get_error_code() );

		// Two hours later: the abandoned checkout no longer holds the use.
		global $wbcom_credits_test_options;
		foreach ( $wbcom_credits_test_options as $key => $value ) {
			if ( is_array( $value ) && isset( $value['created_at'] ) ) {
				$wbcom_credits_test_options[ $key ]['created_at'] = time() - 2 * HOUR_IN_SECONDS;
			}
		}
		$this->assertSame( 0, Coupons::usage( self::SLUG, 'HALF' ), 'An abandoned hold lapses.' );
	}

	public function test_a_busy_coupon_is_refused_not_double_used(): void {
		global $wpdb;
		$this->coupons( 1 );
		$wpdb->lock_result = '0';

		$result = $this->checkout( 'FREE' );

		$this->assertSame( 'coupon_busy', $result->get_error_code() );
		$this->assertSame( 0, Credits::get_balance( self::SLUG, self::USER ) );
	}
}
