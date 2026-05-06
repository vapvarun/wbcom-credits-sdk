<?php
/**
 * Idempotency tests — proves replayed webhook events are no-ops and the
 * FIFO ring trims correctly when MAX_EVENTS is exceeded.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Gateways\Idempotency;

final class IdempotencyTest extends TestCase {

	protected function setUp(): void {
		Idempotency::reset_for_tests( 'plug', 'stripe' );
	}

	public function test_initial_event_is_recorded(): void {
		self::assertFalse( Idempotency::is_processed( 'plug', 'stripe', 'evt_1' ) );
		self::assertTrue( Idempotency::mark_processed( 'plug', 'stripe', 'evt_1' ) );
		self::assertTrue( Idempotency::is_processed( 'plug', 'stripe', 'evt_1' ) );
	}

	public function test_duplicate_event_returns_false(): void {
		Idempotency::mark_processed( 'plug', 'stripe', 'evt_dup' );
		self::assertFalse(
			Idempotency::mark_processed( 'plug', 'stripe', 'evt_dup' ),
			'Second mark for same event must report duplicate.'
		);
	}

	public function test_empty_event_id_is_ignored(): void {
		self::assertFalse( Idempotency::mark_processed( 'plug', 'stripe', '' ) );
		self::assertFalse( Idempotency::is_processed( 'plug', 'stripe', '' ) );
	}

	public function test_per_gateway_isolation(): void {
		Idempotency::mark_processed( 'plug', 'stripe', 'evt_x' );
		self::assertFalse(
			Idempotency::is_processed( 'plug', 'paypal', 'evt_x' ),
			'Stripe ring must not bleed into PayPal ring.'
		);
	}

	public function test_per_slug_isolation(): void {
		Idempotency::mark_processed( 'plug-a', 'stripe', 'evt_y' );
		self::assertFalse(
			Idempotency::is_processed( 'plug-b', 'stripe', 'evt_y' ),
			'Per-slug isolation: a different consuming plugin must not collide.'
		);
	}

	public function test_fifo_trim_at_max_events(): void {
		// Push 1010 events; the first 10 should fall off the ring.
		for ( $i = 1; $i <= 1010; $i++ ) {
			Idempotency::mark_processed( 'plug', 'stripe', 'evt_' . $i );
		}
		self::assertFalse(
			Idempotency::is_processed( 'plug', 'stripe', 'evt_1' ),
			'Oldest event must be evicted when ring exceeds MAX_EVENTS.'
		);
		self::assertTrue(
			Idempotency::is_processed( 'plug', 'stripe', 'evt_1010' ),
			'Newest event must remain in the ring.'
		);
	}
}
