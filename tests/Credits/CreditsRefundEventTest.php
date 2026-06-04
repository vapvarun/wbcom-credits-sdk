<?php
/**
 * Credits::refund() event-contract test (FIX C — hold-lifecycle side).
 *
 * The generic `wbcom_credits_refunded` action was harmonized to a single
 * 4-arg shape across both fire sites: ( $slug, $user_id, $amount, $context ).
 * On the hold-lifecycle path (Credits::refund()) the 3rd arg is now the
 * refunded credit AMOUNT (positive int) and the old item_id moved into
 * $context['item_id']. This locks that contract.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Credits;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class CreditsRefundEventTest extends TestCase {

	private const SLUG   = 'refund-credits-plug';
	private const PREFIX = 'rcpg';

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
	}

	public function test_refund_fires_action_with_amount_and_context(): void {
		$fired = array();
		add_action(
			'wbcom_credits_refunded',
			static function ( $slug, $user_id, $amount, $context = array() ) use ( &$fired ): void {
				$fired[] = array( $slug, $user_id, $amount, $context );
			},
			10,
			4
		);

		$ledger_id = Credits::refund( self::SLUG, 5, 12, 777, 'rejected listing' );

		self::assertNotFalse( $ledger_id );
		self::assertCount( 1, $fired, 'Hold refund must fire the generic action exactly once.' );
		self::assertSame( self::SLUG, $fired[0][0] );
		self::assertSame( 5, $fired[0][1] );
		self::assertSame( 12, $fired[0][2], '3rd arg must be the refunded credit amount, not item_id.' );
		self::assertIsArray( $fired[0][3] );
		self::assertSame( 777, $fired[0][3]['item_id'], 'item_id now lives in $context.' );
		self::assertSame( 'hold_refund', $fired[0][3]['reason'] );
		self::assertSame( (int) $ledger_id, $fired[0][3]['ledger_id'] );
		self::assertSame( 'rejected listing', $fired[0][3]['note'] );
	}

	public function test_three_arg_listener_still_receives_slug_and_user(): void {
		// Back-compat: a legacy 3-arg listener must still fire and get the
		// slug + user_id unchanged (it just reads a different 3rd arg now).
		$seen = array();
		add_action(
			'wbcom_credits_refunded',
			static function ( $slug, $user_id, $third ) use ( &$seen ): void {
				$seen[] = array( $slug, $user_id, $third );
			},
			10,
			3
		);

		Credits::refund( self::SLUG, 9, 4, 555, 'note' );

		self::assertCount( 1, $seen );
		self::assertSame( self::SLUG, $seen[0][0] );
		self::assertSame( 9, $seen[0][1] );
		self::assertSame( 4, $seen[0][2], '3-arg listener now reads the amount as the 3rd arg.' );
	}
}
