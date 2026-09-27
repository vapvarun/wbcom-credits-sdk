<?php
/**
 * Checkout money (1.9.0): minor units per currency, pack expiry, coupons,
 * tax, the order total, lapsed lots, billing modes.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Billing;
use Wbcom\Credits\Expiry;
use Wbcom\Credits\Money;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Checkout_Settings;
use Wbcom\Credits\Gateways\Coupons;
use Wbcom\Credits\Gateways\Order;
use Wbcom\Credits\Gateways\Pack_Admin_Renderer;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class CheckoutOrderTest extends TestCase {

	private const SLUG = 'checkout-order';

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_options;
		$wpdb                       = new FakeWpdb();
		$wbcom_credits_test_options = array();
		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => 'cko',
				'version' => '1.0.0',
			)
		);
		\Wbcom\Credits\Gateways\Transaction_Log::maybe_create_table( 'cko' );
	}

	public function test_currency_decimals_come_from_the_complete_registry(): void {
		$this->assertSame( 0, Money::decimals_for( 'JPY' ) );
		$this->assertSame( 0, Money::decimals_for( 'ISK' ) );
		$this->assertSame( 3, Money::decimals_for( 'KWD' ) );
		$this->assertSame( 2, Money::decimals_for( 'KES' ) );
	}

	public function test_pack_prices_are_stored_in_each_currencys_minor_units(): void {
		$yen = Pack_Admin_Renderer::sanitize( array( 'currency' => 'JPY', 'packs' => array( array( 'credits' => 10, 'price' => '500' ) ) ) );
		$this->assertSame( 500, array_values( $yen['packs'] )[0]['price_cents'], 'JPY 500 is 500, not 50000' );

		$dinar = Pack_Admin_Renderer::sanitize( array( 'currency' => 'KWD', 'packs' => array( array( 'credits' => 10, 'price' => '1.234' ) ) ) );
		$this->assertSame( 1234, array_values( $dinar['packs'] )[0]['price_cents'], 'KWD has three decimals' );

		$usd = Pack_Admin_Renderer::sanitize( array( 'currency' => 'usd', 'packs' => array( array( 'credits' => 10, 'price' => '19.99', 'expires_days' => '90' ) ), 'rate' => '1.5' ) );
		$pack = array_values( $usd['packs'] )[0];
		$this->assertSame( 1999, $pack['price_cents'] );
		$this->assertSame( 90, $pack['expires_days'] );
		$this->assertSame( 150, $usd['rate_cents_per_credit'] );
	}

	public function test_an_unknown_currency_falls_back_to_usd(): void {
		$this->assertSame( 'USD', Pack_Admin_Renderer::sanitize( array( 'currency' => 'ZZZ' ) )['currency'] );
	}

	public function test_order_applies_coupon_then_tax(): void {
		update_option( Checkout_Settings::option_name( self::SLUG ), array( 'tax_rate' => 20 ) );
		update_option( Coupons::option_name( self::SLUG ), array( 'SAVE10' => array( 'type' => 'percent', 'amount' => 10, 'expires' => '', 'usage_limit' => 0, 'active' => true ) ) );

		$order = Order::build( self::SLUG, array( 'credits' => 10, 'price_cents' => 5000, 'currency' => 'USD', 'expires_days' => 30 ), 'save10', array( 'billing_email' => 'a@b.test' ) );

		$this->assertSame( 5000, $order['subtotal'] );
		$this->assertSame( 500, $order['discount'] );
		$this->assertSame( 900, $order['tax'], '20% of 45.00' );
		$this->assertSame( 5400, $order['total'] );
		$this->assertSame( 'SAVE10', $order['coupon'] );
		$this->assertSame( 30, $order['expires_days'] );
	}

	public function test_fixed_coupon_is_in_major_units_and_never_exceeds_the_price(): void {
		$this->assertSame( 500, Coupons::discount( array( 'type' => 'fixed', 'amount' => 5.0 ), 5000, 'USD' ) );
		$this->assertSame( 300, Coupons::discount( array( 'type' => 'fixed', 'amount' => 5.0 ), 300, 'USD' ) );
		$this->assertSame( 500, Coupons::discount( array( 'type' => 'fixed', 'amount' => 500.0 ), 1000, 'JPY' ) );
	}

	public function test_invalid_and_expired_coupons_are_refused(): void {
		update_option(
			Coupons::option_name( self::SLUG ),
			array(
				'OLD' => array( 'type' => 'percent', 'amount' => 10, 'expires' => '2000-01-01', 'usage_limit' => 0, 'active' => true ),
				'OFF' => array( 'type' => 'percent', 'amount' => 10, 'expires' => '', 'usage_limit' => 0, 'active' => false ),
			)
		);
		$this->assertSame( 'coupon_expired', Coupons::find( self::SLUG, 'old' )->get_error_code() );
		$this->assertSame( 'coupon_invalid', Coupons::find( self::SLUG, 'OFF' )->get_error_code() );
		$this->assertSame( 'coupon_invalid', Coupons::find( self::SLUG, 'NOPE' )->get_error_code() );
	}

	public function test_coupon_admin_rows_are_normalised(): void {
		$out = Coupons::sanitize( array( 'rows' => array( array( 'code' => ' spring 24! ', 'type' => 'fixed', 'amount' => '5', 'expires' => 'bad', 'usage_limit' => '-3', 'active' => '1' ), array( 'code' => '' ) ) ) );
		$this->assertSame( array( 'SPRING24' ), array_keys( $out ) );
		$this->assertSame( '', $out['SPRING24']['expires'] );
		$this->assertSame( 0, $out['SPRING24']['usage_limit'] );
	}

	public function test_a_lapsed_lot_only_takes_what_is_left_of_it(): void {
		$this->assertSame( 30, Expiry::remaining( 50, 30, 0 ), 'part spent: the rest expires' );
		$this->assertSame( 0, Expiry::remaining( 50, 40, 40 ), 'newer lots cover the balance' );
		$this->assertSame( 50, Expiry::remaining( 50, 200, 0 ), 'never more than the lot granted' );
		$this->assertSame( 10, Expiry::remaining( 50, 30, 20 ) );
	}

	public function test_basic_billing_asks_name_email_country(): void {
		$missing = Billing::missing( array( 'billing_first_name' => 'A', 'billing_email' => 'a@b.test' ), self::SLUG );
		$this->assertSame( array( 'billing_last_name', 'billing_country' ), $missing );

		update_option( Checkout_Settings::option_name( self::SLUG ), array( 'billing_mode' => 'full' ) );
		$this->assertContains( 'billing_address_1', Billing::missing( array(), self::SLUG ) );
	}
}
