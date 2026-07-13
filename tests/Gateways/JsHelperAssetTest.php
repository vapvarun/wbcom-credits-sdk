<?php
/**
 * JS checkout helper asset — reusable script registration.
 *
 * Task 1 of the native-checkout wiring: the SDK registers (but does not
 * enqueue) a `wbcom-credits-checkout` script handle localized with the
 * REST root + nonce so any consuming plugin can call
 * `wp_enqueue_script( 'wbcom-credits-checkout' )` and then
 * `window.wbcomCreditsCheckout(...)` from its own UI. Later tasks (in
 * consuming plugin repos) depend on this exact handle + localized object
 * shape, so this pack locks the contract as CI.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class JsHelperAssetTest extends TestCase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		global $wbcom_credits_test_hooks, $wbcom_credits_test_routes, $wbcom_credits_test_scripts;
		$wbcom_credits_test_hooks  = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_routes = array();
		$wbcom_credits_test_scripts = array();
	}

	public function test_checkout_script_registers_with_rest_config(): void {
		Registry::instance()->register(
			array(
				'slug'   => 'demo-plugin',
				'prefix' => 'jshelper',
			)
		);
		Registry::instance()->boot_all();

		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( 'wbcom-credits-checkout', 'registered' ) );

		$data = (string) wp_scripts()->get_data( 'wbcom-credits-checkout', 'data' );
		$this->assertStringContainsString( 'wbcomCreditsCfg', $data );
		$this->assertStringContainsString( 'restRoot', $data );

		// Pull the localized JSON out of "var wbcomCreditsCfg = {...};" and
		// confirm restRoot matches the confirmed Webhook_Controller::NAMESPACE
		// ('wbcom-credits/v1') — the exact namespace the checkout route lives
		// under, so window.wbcomCreditsCheckout() posts to the right URL.
		$this->assertMatchesRegularExpression( '/^var wbcomCreditsCfg = (\{.+\});$/', $data );
		$json   = preg_replace( '/^var wbcomCreditsCfg = (\{.+\});$/', '$1', $data );
		$config = json_decode( (string) $json, true );

		$this->assertIsArray( $config );
		$this->assertSame( 'http://example.org/wp-json/wbcom-credits/v1/', $config['restRoot'] );
		$this->assertNotEmpty( $config['nonce'] );
	}

	public function test_script_registration_is_guarded_against_double_registration(): void {
		Registry::instance()->register(
			array(
				'slug'   => 'plugin-a',
				'prefix' => 'jsa',
			)
		);
		Registry::instance()->register(
			array(
				'slug'   => 'plugin-b',
				'prefix' => 'jsb',
			)
		);
		Registry::instance()->boot_all();

		// Two consumers both wire the same 'wp_enqueue_scripts' hook; firing
		// it must not error or double-register the shared handle.
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( 'wbcom-credits-checkout', 'registered' ) );

		// The real assertion: the wp_script_is() guard in Registry::boot_all()
		// must stop the second consumer's closure from re-registering +
		// re-localizing the shared handle. wp_localize_script() appends to
		// the handle's 'data' rather than overwriting it, so if the guard
		// were removed the config object would be localized twice and show
		// up twice in the concatenated data string.
		$data = (string) wp_scripts()->get_data( 'wbcom-credits-checkout', 'data' );
		$this->assertSame( 1, substr_count( $data, 'wbcomCreditsCfg' ), 'Config must be localized exactly once (double-registration guard).' );
	}
}
