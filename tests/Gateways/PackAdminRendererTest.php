<?php
/**
 * Pack_Admin_Renderer tests — reusable admin pack-editor renderer + sanitizer.
 *
 * Covers the sanitize() contract that feeds the `pricing`-shaped array
 * Pricing::resolve() consumes, and the render() output a consuming plugin
 * hands to its own settings page.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Gateways\Pack_Admin_Renderer;

final class PackAdminRendererTest extends TestCase {

	public function test_sanitize_normalizes_packs_and_drops_invalid(): void {
		$raw = array(
			'currency' => 'usd',
			'packs'    => array(
				array(
					'credits' => '10',
					'price'   => '29',
				),
				array(
					'credits' => '0',
					'price'   => '5',
				), // dropped
			),
			'custom_enabled' => '1',
			'rate_cents'     => '198',
			'min_credits'    => '1',
			'max_credits'    => '500',
		);

		$out = Pack_Admin_Renderer::sanitize( $raw );

		$this->assertSame( 'USD', $out['currency'] );
		$this->assertCount( 1, $out['packs'] );
		$this->assertSame( 2900, array_values( $out['packs'] )[0]['price_cents'] );
		$this->assertTrue( $out['custom_enabled'] );
		$this->assertSame( 500, $out['max_credits'] );
	}

	public function test_sanitize_drops_zero_price_rows(): void {
		$raw = array(
			'packs' => array(
				array(
					'credits' => '10',
					'price'   => '0',
				),
			),
		);

		$out = Pack_Admin_Renderer::sanitize( $raw );

		$this->assertSame( array(), $out['packs'] );
	}

	public function test_sanitize_defaults_on_empty_input(): void {
		$out = Pack_Admin_Renderer::sanitize( array() );

		$this->assertSame( 'USD', $out['currency'] );
		$this->assertSame( array(), $out['packs'] );
		$this->assertFalse( $out['custom_enabled'] );
		$this->assertSame( 0, $out['rate_cents_per_credit'] );
		$this->assertSame( 1, $out['min_credits'] );
	}

	public function test_sanitize_rejects_non_array_input(): void {
		$out = Pack_Admin_Renderer::sanitize( 'not-an-array' );

		$this->assertSame( array(), $out['packs'] );
		$this->assertSame( 'USD', $out['currency'] );
	}

	public function test_render_emits_container_with_option_name(): void {
		ob_start();
		Pack_Admin_Renderer::render( 'demo_pricing' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-option="demo_pricing"', $html );
		$this->assertStringContainsString( 'demo_pricing[currency]', $html );
		$this->assertStringContainsString( 'demo_pricing[custom_enabled]', $html );
		$this->assertStringContainsString( 'demo_pricing[rate_cents]', $html );
		$this->assertStringContainsString( 'demo_pricing[min_credits]', $html );
		$this->assertStringContainsString( 'demo_pricing[max_credits]', $html );
	}

	public function test_render_shows_saved_pack_as_dollars(): void {
		update_option(
			'demo_pricing_saved',
			array(
				'currency' => 'USD',
				'packs'    => array(
					'pack_0' => array(
						'credits'     => 100,
						'price_cents' => 2900,
					),
				),
			)
		);

		ob_start();
		Pack_Admin_Renderer::render( 'demo_pricing_saved' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'demo_pricing_saved[packs][0][credits]', $html );
		$this->assertMatchesRegularExpression(
			'/demo_pricing_saved\[packs\]\[0\]\[price\]"\s+value="29"/',
			$html
		);
	}

	public function test_render_emits_blank_spare_rows_when_no_packs_saved(): void {
		ob_start();
		Pack_Admin_Renderer::render( 'demo_pricing_blank' );
		$html = (string) ob_get_clean();

		// Three spare rows: indices 0, 1, 2.
		$this->assertStringContainsString( 'demo_pricing_blank[packs][0][credits]', $html );
		$this->assertStringContainsString( 'demo_pricing_blank[packs][1][credits]', $html );
		$this->assertStringContainsString( 'demo_pricing_blank[packs][2][credits]', $html );
		$this->assertStringNotContainsString( 'demo_pricing_blank[packs][3][credits]', $html );
	}
}
