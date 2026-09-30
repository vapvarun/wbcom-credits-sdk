<?php
/**
 * Receipt::can_view(): who may see a receipt (1.10.0).
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Receipt;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class ReceiptCanViewTest extends TestCase {

	private const SLUG = 'rcpt-plug';

	private int $log_id = 0;

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_options, $wbcom_credits_test_hooks, $wbcom_credits_test_admins;
		$wpdb                       = new FakeWpdb();
		$wbcom_credits_test_options = array();
		$wbcom_credits_test_hooks   = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_admins  = array( 7 );

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setValue( null, null );

		Registry::instance()->register( array( 'slug' => self::SLUG, 'prefix' => 'rcpt', 'version' => '1.0.0' ) );
		Transaction_Log::maybe_create_table( 'rcpt' );
		$this->log_id = Transaction_Log::insert_checkout(
			array(
				'slug'         => self::SLUG,
				'gateway'      => Stripe::ID,
				'session_id'   => 'cs_own',
				'event_id'     => 'ev_own',
				'user_id'      => 42,
				'credits'      => 10,
				'amount_cents' => 1000,
			)
		);
	}

	public function test_the_buyer_sees_their_receipt(): void {
		$data = Receipt::can_view( 42, self::SLUG, $this->log_id );
		$this->assertIsArray( $data );
		$this->assertSame( 42, $data['user_id'] );
	}

	public function test_another_user_does_not(): void {
		$this->assertNull( Receipt::can_view( 99, self::SLUG, $this->log_id ) );
	}

	public function test_an_admin_sees_any_receipt(): void {
		$this->assertIsArray( Receipt::can_view( 7, self::SLUG, $this->log_id ) );
	}

	public function test_a_guest_or_unknown_slug_or_row_sees_nothing(): void {
		$this->assertNull( Receipt::can_view( 0, self::SLUG, $this->log_id ) );
		$this->assertNull( Receipt::can_view( 42, 'not-registered', $this->log_id ) );
		$this->assertNull( Receipt::can_view( 42, self::SLUG, 999999 ) );
	}

	public function test_the_url_filter_lets_a_consumer_serve_its_own_page(): void {
		add_filter( 'wbcom_credits_receipt_url', static fn ( $url, $slug, $id ) => 'https://example.test/receipt/' . $id, 10, 3 );
		$this->assertSame( 'https://example.test/receipt/' . $this->log_id, Receipt::url( self::SLUG, $this->log_id ) );
	}
}
