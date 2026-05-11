<?php
/**
 * Security regression — issue #2.
 *
 * Pre-1.3.0 the /checkout/{gateway} REST endpoint accepted client-supplied
 * `price_cents`, so any logged-in user could POST credits=10000 +
 * price_cents=1 and walk away with 10,000 credits for 1¢. This test pack
 * locks the server-authoritative pricing contract that closes the hole.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Gateways\Pricing;
use Wbcom\Credits\Gateways\PricingException;
use Wbcom\Credits\Registry;

final class PricingTest extends TestCase {

	protected function setUp(): void {
		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	private function register_with_packs(): void {
		Registry::instance()->register(
			array(
				'slug'    => 'demo-plugin',
				'prefix'  => 'demo',
				'pricing' => array(
					'currency' => 'USD',
					'packs'    => array(
						'starter' => array( 'credits' => 100, 'price_cents' => 1000 ),
						'pro'     => array( 'credits' => 500, 'price_cents' => 4500 ),
					),
				),
			)
		);
	}

	private function register_with_callback( int $rate = 10, int $min = 10, int $max = 5000 ): void {
		Registry::instance()->register(
			array(
				'slug'    => 'demo-plugin',
				'prefix'  => 'demo',
				'pricing' => array(
					'currency'               => 'USD',
					'credits_to_price_cents' => static fn ( int $credits ): int => $credits * $rate,
					'min_credits'            => $min,
					'max_credits'            => $max,
				),
			)
		);
	}

	public function test_pack_mode_resolves_server_authoritative_price(): void {
		$this->register_with_packs();

		$result = Pricing::resolve( 'demo-plugin', array( 'pack_id' => 'starter' ) );

		$this->assertSame( 100, $result['credits'] );
		$this->assertSame( 1000, $result['price_cents'] );
		$this->assertSame( 'USD', $result['currency'] );
		$this->assertSame( 'pack', $result['mode'] );
		$this->assertSame( 'starter', $result['pack_id'] );
	}

	public function test_pack_mode_ignores_client_supplied_price_cents(): void {
		$this->register_with_packs();

		// The exploit input — attacker sends pack_id=pro plus a fake
		// price_cents=1. Pricing must ignore the client value entirely.
		$result = Pricing::resolve(
			'demo-plugin',
			array(
				'pack_id'     => 'pro',
				'price_cents' => 1, // <- attacker payload, must be ignored.
				'credits'     => 999999, // <- ditto.
			)
		);

		$this->assertSame( 500, $result['credits'], 'credits MUST come from server-side pack, not client' );
		$this->assertSame( 4500, $result['price_cents'], 'price_cents MUST come from server-side pack, not client' );
	}

	public function test_unknown_pack_id_throws_404(): void {
		$this->register_with_packs();

		try {
			Pricing::resolve( 'demo-plugin', array( 'pack_id' => 'enterprise' ) );
			$this->fail( 'Expected PricingException for unknown pack' );
		} catch ( PricingException $e ) {
			$this->assertSame( 'unknown_pack', $e->error_code );
			$this->assertSame( 404, $e->http_status );
		}
	}

	public function test_callback_mode_computes_price_from_credits(): void {
		$this->register_with_callback( rate: 10, min: 10, max: 5000 );

		$result = Pricing::resolve( 'demo-plugin', array( 'credits' => 250 ) );

		$this->assertSame( 250, $result['credits'] );
		$this->assertSame( 2500, $result['price_cents'] );
		$this->assertSame( 'callback', $result['mode'] );
	}

	public function test_callback_mode_ignores_client_supplied_price_cents(): void {
		$this->register_with_callback();

		$result = Pricing::resolve(
			'demo-plugin',
			array(
				'credits'     => 100,
				'price_cents' => 1, // <- attacker payload, must be ignored.
			)
		);

		$this->assertSame( 1000, $result['price_cents'], '100 credits × $0.10/credit = $10.00 = 1000¢. Client price_cents=1 must be ignored.' );
	}

	public function test_callback_mode_enforces_min_credits(): void {
		$this->register_with_callback( rate: 10, min: 50 );

		try {
			Pricing::resolve( 'demo-plugin', array( 'credits' => 1 ) );
			$this->fail( 'Expected PricingException for credits below min' );
		} catch ( PricingException $e ) {
			$this->assertSame( 'credits_out_of_bounds', $e->error_code );
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_callback_mode_enforces_max_credits(): void {
		$this->register_with_callback( rate: 10, max: 1000 );

		try {
			Pricing::resolve( 'demo-plugin', array( 'credits' => 10000 ) );
			$this->fail( 'Expected PricingException for credits above max' );
		} catch ( PricingException $e ) {
			$this->assertSame( 'credits_out_of_bounds', $e->error_code );
		}
	}

	public function test_missing_pricing_config_throws_503(): void {
		Registry::instance()->register(
			array(
				'slug'   => 'demo-plugin',
				'prefix' => 'demo',
				// No 'pricing' key — security boundary closed.
			)
		);

		try {
			Pricing::resolve( 'demo-plugin', array( 'pack_id' => 'starter' ) );
			$this->fail( 'Expected PricingException for missing pricing' );
		} catch ( PricingException $e ) {
			$this->assertSame( 'pricing_not_configured', $e->error_code );
			$this->assertSame( 503, $e->http_status );
		}
	}

	public function test_unknown_slug_throws_404(): void {
		try {
			Pricing::resolve( 'never-registered', array( 'pack_id' => 'starter' ) );
			$this->fail( 'Expected PricingException for unknown slug' );
		} catch ( PricingException $e ) {
			$this->assertSame( 'plugin_not_registered', $e->error_code );
			$this->assertSame( 404, $e->http_status );
		}
	}

	public function test_no_pack_id_no_credits_throws_400(): void {
		$this->register_with_packs();

		try {
			Pricing::resolve( 'demo-plugin', array() );
			$this->fail( 'Expected PricingException for missing input' );
		} catch ( PricingException $e ) {
			$this->assertSame( 'missing_input', $e->error_code );
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_callback_returns_zero_throws_500(): void {
		Registry::instance()->register(
			array(
				'slug'    => 'demo-plugin',
				'prefix'  => 'demo',
				'pricing' => array(
					'credits_to_price_cents' => static fn ( int $c ): int => 0,
					'min_credits'            => 1,
				),
			)
		);

		try {
			Pricing::resolve( 'demo-plugin', array( 'credits' => 100 ) );
			$this->fail( 'Expected PricingException for invalid callback result' );
		} catch ( PricingException $e ) {
			$this->assertSame( 'invalid_callback_result', $e->error_code );
			$this->assertSame( 500, $e->http_status );
		}
	}

	public function test_pack_with_invalid_credits_or_price_throws_500(): void {
		Registry::instance()->register(
			array(
				'slug'    => 'demo-plugin',
				'prefix'  => 'demo',
				'pricing' => array(
					'packs' => array(
						'broken' => array( 'credits' => 0, 'price_cents' => 1000 ),
					),
				),
			)
		);

		try {
			Pricing::resolve( 'demo-plugin', array( 'pack_id' => 'broken' ) );
			$this->fail( 'Expected PricingException for invalid pack contents' );
		} catch ( PricingException $e ) {
			$this->assertSame( 'invalid_pack', $e->error_code );
		}
	}
}
