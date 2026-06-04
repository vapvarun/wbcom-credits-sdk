<?php
/**
 * Gateway refund event test (HIGH-5).
 *
 * A gateway-initiated refund revokes credits via Credits::adjust() and logs
 * a Transaction_Log refund row. Before the fix it fired only the
 * gateway-scoped `wbcom_credits_gateway_refund` action and silently skipped
 * the SDK's generic `wbcom_credits_refunded` action — so consumer refund
 * consumers (audit log, outgoing webhooks, notifications) never ran for
 * gateway refunds. This locks that the generic action now fires with the
 * documented ($slug, $user_id, $item_id) payload.
 *
 * Also locks the claim-then-act idempotency flow on the refund path: a
 * replayed refund webhook must NOT revoke credits a second time and must
 * NOT re-fire the refund event.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Gateways\Fixtures\Test_Gateway;
use Wbcom\Credits\Tests\Support\FakeWpdb;

require_once __DIR__ . '/Fixtures/Test_Gateway.php';

final class GatewayRefundEventTest extends TestCase {

	private const SLUG    = 'refund-plug';
	private const PREFIX  = 'rfpg';

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$balance_cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$balance_cache->setAccessible( true );
		$balance_cache->setValue( null, array() );

		global $wbcom_credits_test_hooks, $wbcom_credits_test_routes;
		$wbcom_credits_test_hooks  = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_routes = array();

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);

		// Build the schema the gateway flow touches.
		\Wbcom\Credits\Ledger::maybe_create_table( self::PREFIX );
		Transaction_Log::maybe_create_table( self::PREFIX );
		Processed_Events::maybe_create_table( self::PREFIX );

		// Seed a completed checkout: 100 credits for $10.00.
		Transaction_Log::insert_checkout(
			array(
				'slug'           => self::SLUG,
				'gateway'        => 'teststripe',
				'session_id'     => 'cs_test_1',
				'payment_intent' => 'pi_test_1',
				'event_id'       => 'evt_checkout_1',
				'user_id'        => 7,
				'credits'        => 100,
				'amount_cents'   => 1000,
				'currency'       => 'USD',
				'ledger_id'      => 1,
			)
		);
		// Mirror the topup in the ledger so balance starts at 100.
		Credits::topup( self::SLUG, 7, 100, 'seed' );
	}

	private function refund_payload( string $event_id, int $amount_cents ): array {
		return array(
			'_event' => array(
				'type'         => \Wbcom\Credits\Gateways\Gateway_Event::TYPE_REFUND,
				'event_id'     => $event_id,
				'session_id'   => 'cs_test_1',
				'amount_cents' => $amount_cents,
				'currency'     => 'USD',
			),
		);
	}

	public function test_full_refund_fires_generic_refunded_action(): void {
		$fired = array();
		add_action(
			'wbcom_credits_refunded',
			static function ( $slug, $user_id, $item_id ) use ( &$fired ): void {
				$fired[] = array( $slug, $user_id, $item_id );
			},
			10,
			3
		);

		$gateway  = new Test_Gateway();
		$response = $gateway->handle_webhook( self::SLUG, $this->refund_payload( 'evt_refund_1', 1000 ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 100, $response->get_data()['credits_revoked'] );
		self::assertCount( 1, $fired, 'Generic wbcom_credits_refunded must fire exactly once.' );
		self::assertSame( array( self::SLUG, 7, 0 ), $fired[0], 'Payload must match the documented (slug, user_id, item_id) contract; item_id is 0 for gateway refunds.' );

		// Credits revoked: balance back to 0.
		self::assertSame( 0, Credits::get_balance( self::SLUG, 7 ) );
	}

	public function test_replayed_refund_does_not_revoke_or_refire(): void {
		$count = 0;
		add_action(
			'wbcom_credits_refunded',
			static function () use ( &$count ): void {
				$count++;
			},
			10,
			3
		);

		$gateway = new Test_Gateway();
		$first   = $gateway->handle_webhook( self::SLUG, $this->refund_payload( 'evt_refund_dup', 1000 ) );
		$second  = $gateway->handle_webhook( self::SLUG, $this->refund_payload( 'evt_refund_dup', 1000 ) );

		self::assertSame( 100, $first->get_data()['credits_revoked'] );
		self::assertTrue( ! empty( $second->get_data()['duplicate'] ), 'Replayed refund must be acked as a duplicate.' );
		self::assertSame( 1, $count, 'Generic refund action must fire only once across a replay.' );
		self::assertSame( 0, Credits::get_balance( self::SLUG, 7 ), 'Balance must not double-revoke on replay.' );
	}

	public function test_partial_refund_prorates_credits_and_fires_once(): void {
		$fired = array();
		add_action(
			'wbcom_credits_refunded',
			static function ( $slug, $user_id, $item_id ) use ( &$fired ): void {
				$fired[] = array( $slug, $user_id, $item_id );
			},
			10,
			3
		);

		// Refund $4.00 of the $10.00 / 100-credit checkout → revoke 40 credits.
		$gateway  = new Test_Gateway();
		$response = $gateway->handle_webhook( self::SLUG, $this->refund_payload( 'evt_refund_partial', 400 ) );

		self::assertSame( 40, $response->get_data()['credits_revoked'] );
		self::assertCount( 1, $fired, 'Partial refund must fire the generic action exactly once.' );
		self::assertSame( array( self::SLUG, 7, 0 ), $fired[0] );
		self::assertSame( 60, Credits::get_balance( self::SLUG, 7 ), 'Balance must reflect the prorated revoke (100 - 40).' );
	}
}
