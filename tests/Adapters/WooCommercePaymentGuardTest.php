<?php
/**
 * WooCommerce adapter payment guard.
 *
 * The adapter credits on `woocommerce_order_status_processing` as well as
 * `..._completed`, and Cash on Delivery, cheque and BACS all move an UNPAID
 * order to `processing`. Without a payment check a buyer could place a COD
 * order, receive the credits at once, spend them, and never pay.
 *
 * This locks the guard AND the choice of signal. The obvious test —
 * `$order->is_paid()` — does not work: it asks whether the STATUS is one of the
 * paid statuses, and `processing` is one of them, so it returns true for an
 * unpaid COD order and a captured card order alike. A guard written on it looks
 * correct and changes nothing. `get_date_paid()` is stamped when money is
 * actually taken, and also when a COD order is finally marked completed, so it
 * separates the two cases and still lets real COD revenue through.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

/**
 * Shadows the global wc_get_order() for calls made from inside this namespace,
 * which is where the adapter calls it from.
 *
 * @param int $order_id Order ID.
 * @return object|false
 */
function wc_get_order( $order_id ) {
	return \Wbcom\Credits\Tests\Adapters\WooCommercePaymentGuardTest::$orders[ (int) $order_id ] ?? false;
}

namespace Wbcom\Credits\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Adapters\WooCommerceAdapter;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Ledger;
use Wbcom\Credits\Tests\Support\FakeWpdb;

/**
 * Minimal stand-in for a WC_Order_Item_Product.
 */
final class FakeOrderItem {

	/** @var int */
	private $product_id;

	/** @var int */
	private $quantity;

	public function __construct( int $product_id, int $quantity = 1 ) {
		$this->product_id = $product_id;
		$this->quantity   = $quantity;
	}

	public function get_product_id(): int {
		return $this->product_id;
	}

	public function get_quantity(): int {
		return $this->quantity;
	}
}

/**
 * Minimal stand-in for a WC_Order.
 */
final class FakeOrder {

	/** @var int */
	public $customer_id;

	/** @var string|null */
	public $date_paid;

	/** @var array */
	public $items;

	/** @var array Meta written back by the adapter. */
	public $meta = array();

	/** @var int Times the adapter saved this order. */
	public $saves = 0;

	public function __construct( int $customer_id, ?string $date_paid, array $items ) {
		$this->customer_id = $customer_id;
		$this->date_paid   = $date_paid;
		$this->items       = $items;
	}

	public function get_customer_id(): int {
		return $this->customer_id;
	}

	/**
	 * Null until WooCommerce records that money was taken.
	 *
	 * @return string|null
	 */
	public function get_date_paid() {
		return $this->date_paid;
	}

	public function get_items(): array {
		return $this->items;
	}

	/**
	 * The adapter stamps a human-readable marker after crediting. Recorded so a
	 * test can tell a credited order from a skipped one without reading the
	 * ledger, and so the call does not fatal on a stand-in.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 */
	public function update_meta_data( $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save(): void {
		++$this->saves;
	}
}

final class WooCommercePaymentGuardTest extends TestCase {

	/** @var array<int,FakeOrder> Orders visible to the shadowed wc_get_order(). */
	public static $orders = array();

	private const SLUG    = 'guard-plug';
	private const USER    = 501;
	private const PRODUCT = 6978;

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_options, $wbcom_credits_test_filters, $wbcom_credits_test_hooks;

		$wpdb                       = new FakeWpdb();
		$wbcom_credits_test_options = array();
		$wbcom_credits_test_filters = array();
		$wbcom_credits_test_hooks   = array();
		self::$orders               = array();

		// Reset the statics that survive between tests. Without this a balance
		// cached by an earlier test is read here and every assertion measures
		// the previous test's outcome — which shows up as the guard "failing"
		// on a case that actually works.
		$registry_instance = new ReflectionProperty( Registry::class, 'instance' );
		$registry_instance->setAccessible( true );
		$registry_instance->setValue( null, null );

		$balance_cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$balance_cache->setAccessible( true );
		$balance_cache->setValue( null, array() );

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => 'grd',
				'version' => '1.0.0',
			)
		);

		// The claim is an INSERT IGNORE against a UNIQUE key. FakeWpdb only
		// enforces that once the CREATE TABLE has declared it, so without these
		// the dedupe silently does nothing and a double delivery credits twice.
		Ledger::maybe_create_table( 'grd' );
		Processed_Events::maybe_create_table( 'grd' );

		update_option(
			self::SLUG . '_credit_mappings',
			array(
				array(
					'adapter' => 'woocommerce',
					'item_id' => self::PRODUCT,
					'credits' => 50,
				),
			)
		);
	}

	/**
	 * Build an adapter bound to the test consumer.
	 */
	private function adapter(): WooCommerceAdapter {
		$adapter = new WooCommerceAdapter();
		$adapter->register_hooks( self::SLUG );

		return $adapter;
	}

	/**
	 * An unpaid order credits nothing.
	 *
	 * The COD case: status moved to `processing`, no money taken, `date_paid`
	 * still null. This is the whole bug.
	 */
	public function test_unpaid_order_credits_nothing(): void {
		self::$orders[901] = new FakeOrder(
			self::USER,
			null,
			array( new FakeOrderItem( self::PRODUCT ) )
		);

		$this->adapter()->on_order_completed( 901 );

		$this->assertSame( 0, Credits::get_balance( self::SLUG, self::USER ) );
	}

	/**
	 * A paid order credits normally.
	 */
	public function test_paid_order_credits(): void {
		self::$orders[902] = new FakeOrder(
			self::USER,
			'2026-08-21 12:00:00',
			array( new FakeOrderItem( self::PRODUCT ) )
		);

		$this->adapter()->on_order_completed( 902 );

		$this->assertSame( 50, Credits::get_balance( self::SLUG, self::USER ) );
	}

	/**
	 * An order that was unpaid and is LATER paid still credits.
	 *
	 * The trap this guards. The guard sits before the idempotency claim, so an
	 * unpaid pass must not consume the order's event id — if it did, the COD
	 * order that a shop later marks completed would find the claim already
	 * taken and credit nothing, ever. That converts crediting too early into
	 * never crediting, which is worse and much harder to notice.
	 */
	public function test_unpaid_then_paid_still_credits(): void {
		self::$orders[903] = new FakeOrder(
			self::USER,
			null,
			array( new FakeOrderItem( self::PRODUCT ) )
		);

		$adapter = $this->adapter();

		// Cash on delivery: order goes to processing, unpaid.
		$adapter->on_order_completed( 903 );
		$this->assertSame( 0, Credits::get_balance( self::SLUG, self::USER ) );

		// The shop receives the cash and marks it completed.
		self::$orders[903]->date_paid = '2026-08-21 13:00:00';
		$adapter->on_order_completed( 903 );

		$this->assertSame( 50, Credits::get_balance( self::SLUG, self::USER ) );
	}

	/**
	 * A paid order delivered twice credits once.
	 *
	 * `processing` and `completed` both reach this handler for a card order.
	 */
	public function test_paid_order_delivered_twice_credits_once(): void {
		self::$orders[904] = new FakeOrder(
			self::USER,
			'2026-08-21 12:00:00',
			array( new FakeOrderItem( self::PRODUCT ) )
		);

		$adapter = $this->adapter();
		$adapter->on_order_completed( 904 );
		$adapter->on_order_completed( 904 );

		$this->assertSame( 50, Credits::get_balance( self::SLUG, self::USER ) );
	}
}
