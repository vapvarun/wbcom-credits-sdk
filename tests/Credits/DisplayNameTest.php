<?php
/**
 * Countries / Currencies display_name(): CLDR names from PHP intl (1.10.0).
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Credits;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Support\Countries;
use Wbcom\Credits\Support\Currencies;

final class DisplayNameTest extends TestCase {

	protected function setUp(): void {
		if ( ! extension_loaded( 'intl' ) ) {
			$this->markTestSkipped( 'intl is not loaded.' );
		}
	}

	public function test_country_names_follow_the_locale(): void {
		$this->assertSame( 'Germany', Countries::display_name( 'de', 'en_US' ) );
		$this->assertSame( 'Deutschland', Countries::display_name( 'DE', 'de_DE' ) );
		$this->assertSame( 'États-Unis', Countries::display_name( 'US', 'fr_FR' ) );
	}

	public function test_currency_names_follow_the_locale(): void {
		$this->assertSame( 'US Dollar', Currencies::display_name( 'usd', 'en_US' ) );
		$this->assertSame( 'US-Dollar', Currencies::display_name( 'USD', 'de_DE' ) );
	}

	public function test_unknown_or_empty_codes_fall_back_to_the_code(): void {
		$this->assertSame( 'ZZ', Countries::display_name( 'zz', 'en_US' ) );
		$this->assertSame( 'XYZ', Currencies::display_name( 'XYZ', 'en_US' ) );
		$this->assertSame( '', Countries::display_name( '', 'en_US' ) );
	}

	public function test_code_lists_are_uppercase_iso(): void {
		$this->assertContains( 'US', Countries::codes() );
		$this->assertContains( 'EUR', Currencies::codes() );
		foreach ( array_merge( Countries::codes(), Currencies::codes() ) as $code ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{2,3}$/', $code );
		}
	}
}
