<?php
/**
 * Gateway_Event DTO tests — sanity check that the type constants match
 * the values that the orchestrator switches on.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Gateways\Gateway_Event;

final class GatewayEventTest extends TestCase {

	public function test_constructor_stores_all_fields(): void {
		$evt = new Gateway_Event(
			type: Gateway_Event::TYPE_CHECKOUT_COMPLETED,
			event_id: 'evt_1',
			session_id: 'cs_1',
			amount_cents: 999,
			currency: 'USD',
			raw: array( 'foo' => 'bar' )
		);

		self::assertSame( 'checkout.completed', $evt->type );
		self::assertSame( 'evt_1', $evt->event_id );
		self::assertSame( 'cs_1', $evt->session_id );
		self::assertSame( 999, $evt->amount_cents );
		self::assertSame( 'USD', $evt->currency );
		self::assertSame( array( 'foo' => 'bar' ), $evt->raw );
	}

	public function test_type_constants_are_stable(): void {
		// The orchestrator (Abstract_Gateway::handle_webhook) switches on
		// these values literally — pinning them here so a rename doesn't
		// silently break webhook routing.
		self::assertSame( 'checkout.completed', Gateway_Event::TYPE_CHECKOUT_COMPLETED );
		self::assertSame( 'refund', Gateway_Event::TYPE_REFUND );
	}
}
