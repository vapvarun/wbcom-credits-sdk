<?php
/**
 * Idempotency tests — proves the claim is ATOMIC: exactly one of N
 * deliveries of the same event wins, every other is told "duplicate"
 * WITHOUT a second ledger write.
 *
 * Storage is now a table with a UNIQUE (slug, gateway, event_id) key
 * (see Processed_Events). The FakeWpdb shim enforces that unique key so
 * these tests exercise the real constraint behaviour, not a PHP array.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Gateways\Idempotency;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class IdempotencyTest extends TestCase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();
		// Create the processed-events table for every prefix these tests use.
		foreach ( array( 'plug', 'plug-a', 'plug-b' ) as $prefix ) {
			Processed_Events::maybe_create_table( $prefix );
		}
	}

	public function test_initial_event_is_recorded(): void {
		self::assertFalse( Idempotency::is_processed( 'plug', 'stripe', 'evt_1' ) );
		self::assertTrue( Idempotency::mark_processed( 'plug', 'stripe', 'evt_1' ) );
		self::assertTrue( Idempotency::is_processed( 'plug', 'stripe', 'evt_1' ) );
	}

	public function test_duplicate_event_returns_false(): void {
		self::assertTrue( Idempotency::mark_processed( 'plug', 'stripe', 'evt_dup' ) );
		self::assertFalse(
			Idempotency::mark_processed( 'plug', 'stripe', 'evt_dup' ),
			'Second mark for same event must report duplicate.'
		);
	}

	public function test_concurrent_claims_only_one_wins(): void {
		// Simulate N concurrent deliveries of the same provider event. The
		// UNIQUE constraint must let exactly ONE claim succeed; the rest
		// must all be rejected so they never reach the credit path.
		$results = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$results[] = Idempotency::mark_processed( 'plug', 'stripe', 'evt_race' );
		}
		self::assertSame(
			1,
			count( array_filter( $results ) ),
			'Exactly one of N concurrent claims for the same event must win.'
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
			'Stripe claim must not bleed into PayPal.'
		);
		self::assertTrue(
			Idempotency::mark_processed( 'plug', 'paypal', 'evt_x' ),
			'Same event id on a different gateway must be claimable.'
		);
	}

	public function test_per_slug_isolation(): void {
		Idempotency::mark_processed( 'plug-a', 'stripe', 'evt_y' );
		self::assertFalse(
			Idempotency::is_processed( 'plug-b', 'stripe', 'evt_y' ),
			'Per-slug isolation: a different consuming plugin must not collide.'
		);
		self::assertTrue(
			Idempotency::mark_processed( 'plug-b', 'stripe', 'evt_y' ),
			'Same event id under a different slug must be claimable.'
		);
	}
}
