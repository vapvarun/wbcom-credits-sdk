<?php
/**
 * PayPal refund linkage test (M-H2).
 *
 * The parent checkout row is keyed by the PayPal ORDER id (what we store in
 * Pending_Checkouts / Transaction_Log). A PAYMENT.CAPTURE.REFUNDED webhook
 * does not reliably carry that order id, so before the fix the refund never
 * matched the recorded parent and credits were never revoked.
 *
 * This locks all three resolution paths, in priority order:
 *   1. PREFERRED — the order id stamped into the purchase-unit custom_id at
 *      checkout (PayPal copies custom_id onto the capture + refund resource).
 *   2. supplementary_data.related_ids.order_id when PayPal includes it.
 *   3. FALLBACK — Transaction_Log lookup keyed on the original CAPTURE id
 *      (related_ids.captured_payment / up_id), recorded as provider_ref on the
 *      checkout row (the PayPal analogue of Stripe's payment_intent fallback).
 *
 * Also locks: the checkout normalizer captures the capture id as provider_ref,
 * and an unresolvable refund returns a null event (200-ack, no credit move).
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Gateway_Event;
use Wbcom\Credits\Gateways\PayPal;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

require_once __DIR__ . '/../../src/Gateways/PayPal.php';

final class PayPalRefundLinkageTest extends TestCase {

	private const SLUG   = 'paypal-plug';
	private const PREFIX = 'pppg';

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		global $wbcom_credits_test_hooks;
		$wbcom_credits_test_hooks = array( 'actions' => array(), 'filters' => array() );

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);

		Transaction_Log::maybe_create_table( self::PREFIX );
		Processed_Events::maybe_create_table( self::PREFIX );

		// Resolve active slug to ours for the capture-id fallback lookup.
		add_filter( 'wbcom_credits_active_slug', static fn() => self::SLUG, 99 );

		// Seed the parent checkout: order PAYPAL_ORDER_1, capture CAPTURE_1.
		Transaction_Log::insert_checkout(
			array(
				'slug'           => self::SLUG,
				'gateway'        => PayPal::ID,
				'session_id'     => 'PAYPAL_ORDER_1',
				'payment_intent' => 'CAPTURE_1',
				'event_id'       => 'evt_checkout',
				'user_id'        => 11,
				'credits'        => 50,
				'amount_cents'   => 500,
				'currency'       => 'USD',
				'ledger_id'      => 1,
			)
		);
	}

	private function refund_payload( string $event_id, array $resource ): array {
		return array(
			'id'         => $event_id,
			'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
			'resource'   => $resource,
		);
	}

	/**
	 * PREFERRED path: refund carries the stamped custom_id with our order id.
	 */
	public function test_custom_id_stamp_resolves_parent(): void {
		$custom_id = wp_json_encode(
			array(
				'slug'    => self::SLUG,
				'user_id' => 11,
				'credits' => 50,
				'session' => 'PAYPAL_ORDER_1',
			)
		);

		$event = ( new PayPal() )->normalize_event(
			$this->refund_payload(
				'evt_refund_a',
				array(
					'custom_id' => $custom_id,
					'amount'    => array( 'value' => '5.00', 'currency_code' => 'USD' ),
				)
			)
		);

		self::assertInstanceOf( Gateway_Event::class, $event );
		self::assertSame( Gateway_Event::TYPE_REFUND, $event->type );
		self::assertSame( 'PAYPAL_ORDER_1', $event->session_id, 'Stamped custom_id must resolve to the order id.' );
		self::assertSame( 500, $event->amount_cents );
		self::assertNotNull( Transaction_Log::find_checkout( self::SLUG, PayPal::ID, $event->session_id ) );
	}

	/**
	 * Secondary path: no custom_id stamp, but related_ids.order_id present.
	 */
	public function test_related_ids_order_id_resolves_parent(): void {
		$event = ( new PayPal() )->normalize_event(
			$this->refund_payload(
				'evt_refund_b',
				array(
					'supplementary_data' => array( 'related_ids' => array( 'order_id' => 'PAYPAL_ORDER_1' ) ),
					'amount'             => array( 'value' => '2.50', 'currency_code' => 'USD' ),
				)
			)
		);

		self::assertInstanceOf( Gateway_Event::class, $event );
		self::assertSame( 'PAYPAL_ORDER_1', $event->session_id );
		self::assertSame( 250, $event->amount_cents );
	}

	/**
	 * FALLBACK path: neither custom_id stamp nor order_id — only the capture id
	 * (captured_payment). Must resolve via Transaction_Log::find_checkout_by_payment_intent().
	 */
	public function test_capture_id_fallback_resolves_parent(): void {
		$event = ( new PayPal() )->normalize_event(
			$this->refund_payload(
				'evt_refund_c',
				array(
					'supplementary_data' => array( 'related_ids' => array( 'captured_payment' => 'CAPTURE_1' ) ),
					'amount'             => array( 'value' => '5.00', 'currency_code' => 'USD' ),
				)
			)
		);

		self::assertInstanceOf( Gateway_Event::class, $event );
		self::assertSame( 'PAYPAL_ORDER_1', $event->session_id, 'Capture-id fallback must translate to the recorded order id.' );
		self::assertSame( 500, $event->amount_cents );
	}

	/**
	 * Unresolvable refund (no stamp, no order id, unknown capture) → null.
	 */
	public function test_unresolvable_refund_returns_null(): void {
		$event = ( new PayPal() )->normalize_event(
			$this->refund_payload(
				'evt_refund_d',
				array(
					'supplementary_data' => array( 'related_ids' => array( 'captured_payment' => 'CAPTURE_UNKNOWN' ) ),
					'amount'             => array( 'value' => '1.00', 'currency_code' => 'USD' ),
				)
			)
		);

		self::assertNull( $event );
	}

	/**
	 * Zero refund amount is a non-event (matches Stripe).
	 */
	public function test_zero_amount_refund_returns_null(): void {
		$custom_id = wp_json_encode( array( 'session' => 'PAYPAL_ORDER_1' ) );

		$event = ( new PayPal() )->normalize_event(
			$this->refund_payload(
				'evt_refund_e',
				array(
					'custom_id' => $custom_id,
					'amount'    => array( 'value' => '0.00', 'currency_code' => 'USD' ),
				)
			)
		);

		self::assertNull( $event );
	}

	/**
	 * The checkout normalizer must capture the capture id as provider_ref so the
	 * fallback lookup has something to resolve against.
	 */
	public function test_checkout_normalizer_captures_capture_id_as_provider_ref(): void {
		$payload = array(
			'id'         => 'evt_co',
			'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
			'resource'   => array(
				'id'                 => 'CAPTURE_NEW',
				'supplementary_data' => array( 'related_ids' => array( 'order_id' => 'PAYPAL_ORDER_NEW' ) ),
				'amount'             => array( 'value' => '8.00', 'currency_code' => 'USD' ),
			),
		);

		$event = ( new PayPal() )->normalize_event( $payload );

		self::assertInstanceOf( Gateway_Event::class, $event );
		self::assertSame( Gateway_Event::TYPE_CHECKOUT_COMPLETED, $event->type );
		self::assertSame( 'PAYPAL_ORDER_NEW', $event->session_id );
		self::assertSame( 'CAPTURE_NEW', $event->provider_ref, 'Checkout event must carry the capture id for later refund linkage.' );
	}
}
