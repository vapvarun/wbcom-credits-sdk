<?php
/**
 * Selling switched off closes every purchase path, not only the gateway route.
 *
 * `wbcom_credits_checkout_enabled` used to gate only the Stripe / PayPal
 * checkout route, so a consumer with selling off still sold credits through a
 * mapped WooCommerce product (Listora card 10337030682): the order was paid
 * and credited while the member could neither see nor spend the credits.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Adapters\WooCommerceAdapter;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;

/**
 * Minimal stand-in for a WC_Product.
 */
final class FakeProduct {

	/** @var int */
	private $id;

	/** @var int */
	private $parent_id;

	public function __construct( int $id, int $parent_id = 0 ) {
		$this->id        = $id;
		$this->parent_id = $parent_id;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_parent_id(): int {
		return $this->parent_id;
	}
}

final class CheckoutGateTest extends TestCase {

	private const SLUG    = 'gate-plug';
	private const MAPPED  = 881;
	private const UNMAPPED = 882;

	protected function setUp(): void {
		global $wbcom_credits_test_options, $wbcom_credits_test_hooks;

		$wbcom_credits_test_options = array();
		$wbcom_credits_test_hooks   = array();

		$instance = new ReflectionProperty( Registry::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => 'gat',
				'version' => '1.0.0',
			)
		);

		update_option(
			self::SLUG . '_credit_mappings',
			array(
				array(
					'adapter' => 'woocommerce',
					'item_id' => self::MAPPED,
					'credits' => 100,
				),
			)
		);
	}

	private function adapter(): WooCommerceAdapter {
		$adapter = new WooCommerceAdapter();
		$adapter->register_hooks( self::SLUG );

		return $adapter;
	}

	private function switch_selling_off(): void {
		add_filter(
			'wbcom_credits_checkout_enabled',
			static function ( $enabled, $slug ) {
				return self::SLUG === $slug ? false : $enabled;
			},
			10,
			2
		);
	}

	public function test_credit_product_sells_while_selling_is_on(): void {
		$this->assertTrue( Credits::checkout_enabled( self::SLUG ) );
		$this->assertTrue( $this->adapter()->gate_purchasable( true, new FakeProduct( self::MAPPED ) ) );
	}

	public function test_credit_product_cannot_be_bought_while_selling_is_off(): void {
		$adapter = $this->adapter();
		$this->switch_selling_off();

		$this->assertFalse( Credits::checkout_enabled( self::SLUG ) );
		$this->assertFalse( $adapter->gate_purchasable( true, new FakeProduct( self::MAPPED ) ) );
		$this->assertFalse( $adapter->gate_purchasable( true, new FakeProduct( 5000, self::MAPPED ) ), 'A variation of a credit product is gated too.' );
	}

	public function test_other_products_are_untouched(): void {
		$adapter = $this->adapter();
		$this->switch_selling_off();

		$this->assertTrue( $adapter->gate_purchasable( true, new FakeProduct( self::UNMAPPED ) ) );
		$this->assertFalse( $adapter->gate_purchasable( false, new FakeProduct( self::UNMAPPED ) ), 'An already unpurchasable product stays so.' );
	}

	public function test_can_purchase_is_false_while_selling_is_off(): void {
		add_filter( 'wbcom_credits_purchase_paths', static fn( $paths ) => array_merge( (array) $paths, array( 'external_url' => true ) ) );
		$this->assertTrue( Credits::can_purchase( self::SLUG ) );

		$this->switch_selling_off();
		$this->assertFalse( Credits::can_purchase( self::SLUG ) );
		$this->assertTrue( in_array( true, Credits::purchase_paths( self::SLUG ), true ), 'Configured routes are still reported.' );
	}
}
