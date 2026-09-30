<?php
/**
 * Gateway_Settings: the headless settings API (1.10.0).
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Gateways\Gateway_Registry;
use Wbcom\Credits\Gateways\Gateway_Settings;
use Wbcom\Credits\Tests\Gateways\Fixtures\Settings_Gateway;

require_once __DIR__ . '/Fixtures/Settings_Gateway.php';

final class GatewaySettingsTest extends TestCase {

	private const SLUG = 'gs-plug';

	protected function setUp(): void {
		global $wbcom_credits_test_options;
		$wbcom_credits_test_options = array();
		Gateway_Registry::reset_for_tests();
		Gateway_Registry::for_slug( self::SLUG )->register( new Settings_Gateway() );
	}

	public function test_fields_carry_no_display_text(): void {
		$fields = Gateway_Settings::fields( new Settings_Gateway() );

		$this->assertSame( array( 'enabled', 'mode', 'public_key', 'secret', 'return' ), array_column( $fields, 'key' ) );
		foreach ( $fields as $field ) {
			$this->assertArrayNotHasKey( 'label', $field );
		}
		$this->assertSame( array( 'test', 'live' ), $fields[1]['options'] );
		$this->assertTrue( $fields[2]['required'] );
		$this->assertFalse( $fields[0]['required'] );
	}

	public function test_save_sanitizes_by_type_and_stores_under_the_frozen_option(): void {
		$saved = Gateway_Settings::save(
			self::SLUG,
			array(
				'fakepay' => array(
					'enabled'    => '1',
					'mode'       => 'LIVE',
					'public_key' => ' pk_1 ',
					'secret'     => 'sk_1',
					'return'     => 'https://example.test/back',
				),
			)
		);

		$this->assertSame( true, $saved['fakepay']['enabled'] );
		$this->assertSame( 'live', $saved['fakepay']['mode'] );
		$this->assertSame( 'pk_1', $saved['fakepay']['public_key'] );
		$this->assertSame( 'sk_1', $saved['fakepay']['secret'] );
		$this->assertSame( $saved, get_option( 'wbcom_credits_gateway_settings_' . self::SLUG ) );
	}

	public function test_a_select_stores_only_one_of_its_options(): void {
		$ok  = Gateway_Settings::save( self::SLUG, array( 'fakepay' => array( 'mode' => 'test' ) ) );
		$bad = Gateway_Settings::save( self::SLUG, array( 'fakepay' => array( 'mode' => 'sandbox' ) ) );

		$this->assertSame( 'test', $ok['fakepay']['mode'] );
		$this->assertSame( '', $bad['fakepay']['mode'] );
	}

	public function test_blank_password_keeps_the_stored_secret(): void {
		Gateway_Settings::save( self::SLUG, array( 'fakepay' => array( 'secret' => 'sk_keep' ) ) );
		$saved = Gateway_Settings::save( self::SLUG, array( 'fakepay' => array( 'secret' => '  ', 'public_key' => 'pk_2' ) ) );

		$this->assertSame( 'sk_keep', $saved['fakepay']['secret'] );
		$this->assertSame( 'pk_2', $saved['fakepay']['public_key'] );
	}

	public function test_a_gateway_missing_from_input_keeps_its_values(): void {
		Gateway_Settings::save( self::SLUG, array( 'fakepay' => array( 'public_key' => 'pk_3' ) ) );
		$saved = Gateway_Settings::save( self::SLUG, array() );

		$this->assertSame( 'pk_3', $saved['fakepay']['public_key'] );
	}

	public function test_save_fires_the_saved_action(): void {
		$seen = array();
		add_action(
			'wbcom_credits_gateway_settings_saved',
			static function ( $slug ) use ( &$seen ) {
				$seen[] = $slug;
			}
		);
		Gateway_Settings::save( self::SLUG, array( 'fakepay' => array( 'enabled' => '1' ) ) );

		$this->assertContains( self::SLUG, $seen );
	}

	public function test_views_mask_secrets_and_flag_what_is_saved(): void {
		Gateway_Settings::save( self::SLUG, array( 'fakepay' => array( 'secret' => 'sk_hidden', 'public_key' => 'pk_4' ) ) );
		$views = array_column( Gateway_Settings::views( self::SLUG ), null, 'id' );
		$view  = $views['fakepay'];

		$this->assertSame( 'fakepay', $view['id'] );
		$this->assertSame( 'FakePay', $view['name'] );
		$this->assertSame( '', $view['values']['secret'] );
		$this->assertTrue( $view['saved']['secret'] );
		$this->assertSame( 'pk_4', $view['values']['public_key'] );
		$this->assertFalse( $view['saved']['return'] );
		$this->assertStringEndsWith( 'wbcom-credits/v1/' . self::SLUG . '/webhook/fakepay', $view['webhook_url'] );
		$this->assertStringNotContainsString( 'sk_hidden', (string) wp_json_encode( $view ) );
	}
}
