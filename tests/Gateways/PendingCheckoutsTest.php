<?php
/**
 * Pending_Checkouts tests — checkout cross-check storage with TTL pruning.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Gateways\Pending_Checkouts;

final class PendingCheckoutsTest extends TestCase {

	protected function setUp(): void {
		Pending_Checkouts::reset_for_tests( 'plug' );
	}

	public function test_put_and_get_round_trip(): void {
		Pending_Checkouts::put(
			'plug',
			'cs_abc',
			array(
				'gateway'     => 'stripe',
				'user_id'     => 42,
				'credits'     => 100,
				'price_cents' => 999,
				'currency'    => 'USD',
			)
		);
		$entry = Pending_Checkouts::get( 'plug', 'cs_abc' );
		self::assertNotNull( $entry );
		self::assertSame( 'stripe', $entry['gateway'] );
		self::assertSame( 42, $entry['user_id'] );
		self::assertSame( 100, $entry['credits'] );
		self::assertSame( 999, $entry['price_cents'] );
		self::assertSame( 'USD', $entry['currency'] );
		self::assertArrayNotHasKey( 'expires_at', $entry, 'TTL field must be stripped from caller-facing payload.' );
	}

	public function test_get_unknown_session_returns_null(): void {
		self::assertNull( Pending_Checkouts::get( 'plug', 'cs_unknown' ) );
	}

	public function test_forget_removes_entry(): void {
		Pending_Checkouts::put(
			'plug',
			'cs_x',
			array(
				'gateway'     => 'stripe',
				'user_id'     => 1,
				'credits'     => 10,
				'price_cents' => 100,
				'currency'    => 'USD',
			)
		);
		Pending_Checkouts::forget( 'plug', 'cs_x' );
		self::assertNull( Pending_Checkouts::get( 'plug', 'cs_x' ) );
	}

	public function test_currency_is_uppercased_on_store(): void {
		Pending_Checkouts::put(
			'plug',
			'cs_eur',
			array(
				'gateway'     => 'stripe',
				'user_id'     => 7,
				'credits'     => 50,
				'price_cents' => 500,
				'currency'    => 'eur',
			)
		);
		$entry = Pending_Checkouts::get( 'plug', 'cs_eur' );
		self::assertSame( 'EUR', $entry['currency'] );
	}

	public function test_expired_entry_is_pruned_on_read(): void {
		// 1-second TTL — sleep past it so the read path prunes.
		Pending_Checkouts::put(
			'plug',
			'cs_old',
			array(
				'gateway'     => 'stripe',
				'user_id'     => 1,
				'credits'     => 1,
				'price_cents' => 1,
				'currency'    => 'USD',
			),
			1
		);
		// The minimum TTL clamp inside put() raises 1s to 60s, so we must
		// instead poke the underlying option to force expiration. Reach
		// past the API to simulate "this entry was stored 24h ago."
		global $wbcom_credits_test_options;
		$key  = 'wbcom_credits_pending_checkouts_plug';
		$blob = $wbcom_credits_test_options[ $key ];
		$blob['cs_old']['expires_at'] = time() - 1;
		$wbcom_credits_test_options[ $key ] = $blob;

		self::assertNull( Pending_Checkouts::get( 'plug', 'cs_old' ) );
	}

	public function test_per_slug_isolation(): void {
		Pending_Checkouts::put(
			'plug-a',
			'cs_shared',
			array(
				'gateway'     => 'stripe',
				'user_id'     => 1,
				'credits'     => 1,
				'price_cents' => 1,
				'currency'    => 'USD',
			)
		);
		self::assertNull( Pending_Checkouts::get( 'plug-b', 'cs_shared' ) );
	}
}
