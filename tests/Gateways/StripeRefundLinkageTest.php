<?php
/**
 * Stripe refund linkage test (HIGH-6).
 *
 * The parent checkout row is keyed by the Checkout Session id (cs_...). A
 * Stripe `charge.refunded` payload carries the PaymentIntent (pi_...), not
 * the session id. Before the fix, normalize_event() set session_id =
 * payment_intent FIRST, so the normal case (payment_intent present) produced
 * a pi_ id that never matched the recorded cs_ id → the parent lookup failed
 * → credits were never revoked.
 *
 * This locks both resolution paths:
 *   1. Normal path: cs_ id stamped onto charge.metadata.wbcom_session
 *      (via payment_intent_data[metadata] at checkout) resolves directly.
 *   2. Legacy/secondary path: no metadata stamp, only payment_intent —
 *      resolved via Transaction_Log::find_checkout_by_payment_intent().
 *
 * In BOTH cases payment_intent is present (the normal Stripe shape) and the
 * parent MUST still resolve.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Gateway_Event;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class StripeRefundLinkageTest extends TestCase {

	private const SLUG   = 'stripe-plug';
	private const PREFIX = 'stpg';

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

		// Resolve active slug to ours for the secondary pi_ lookup.
		add_filter( 'wbcom_credits_active_slug', static fn() => self::SLUG, 99 );

		// Seed the parent checkout: session cs_abc, payment_intent pi_abc.
		Transaction_Log::insert_checkout(
			array(
				'slug'           => self::SLUG,
				'gateway'        => Stripe::ID,
				'session_id'     => 'cs_abc',
				'payment_intent' => 'pi_abc',
				'event_id'       => 'evt_checkout',
				'user_id'        => 9,
				'credits'        => 50,
				'amount_cents'   => 500,
				'currency'       => 'USD',
				'ledger_id'      => 1,
			)
		);
	}

	/**
	 * Normal path: charge carries payment_intent AND the metadata stamp.
	 * Must resolve to the cs_ session id (NOT the pi_ id).
	 */
	public function test_metadata_stamp_resolves_parent_with_payment_intent_present(): void {
		$payload = array(
			'id'   => 'evt_refund_a',
			'type' => 'charge.refunded',
			'data' => array(
				'object' => array(
					'payment_intent'  => 'pi_abc',
					'amount_refunded' => 500,
					'currency'        => 'usd',
					'metadata'        => array( 'wbcom_session' => 'cs_abc' ),
				),
			),
		);

		$event = ( new Stripe() )->normalize_event( $payload );

		self::assertInstanceOf( Gateway_Event::class, $event );
		self::assertSame( Gateway_Event::TYPE_REFUND, $event->type );
		self::assertSame( 'cs_abc', $event->session_id, 'Must resolve to the checkout session id, not the payment_intent.' );
		self::assertSame( 500, $event->amount_cents );

		// And the parent must actually be found by that session id.
		self::assertNotNull( Transaction_Log::find_checkout( self::SLUG, Stripe::ID, $event->session_id ) );
	}

	/**
	 * Legacy/secondary path: no metadata stamp, only payment_intent present.
	 * Must still resolve to cs_abc via the payment_intent lookup.
	 */
	public function test_payment_intent_secondary_lookup_resolves_parent(): void {
		$payload = array(
			'id'   => 'evt_refund_b',
			'type' => 'charge.refunded',
			'data' => array(
				'object' => array(
					'payment_intent'  => 'pi_abc',
					'amount_refunded' => 250,
					'currency'        => 'usd',
					// No metadata stamp (pre-fix session).
				),
			),
		);

		$event = ( new Stripe() )->normalize_event( $payload );

		self::assertInstanceOf( Gateway_Event::class, $event );
		self::assertSame( 'cs_abc', $event->session_id, 'Secondary path must translate pi_ → recorded cs_ id.' );
		self::assertSame( 250, $event->amount_cents );
	}

	/**
	 * Unknown payment_intent with no metadata cannot resolve → null event
	 * (the webhook router will 200-ack without crediting).
	 */
	public function test_unresolvable_charge_returns_null(): void {
		$payload = array(
			'id'   => 'evt_refund_c',
			'type' => 'charge.refunded',
			'data' => array(
				'object' => array(
					'payment_intent'  => 'pi_unknown',
					'amount_refunded' => 100,
					'currency'        => 'usd',
				),
			),
		);

		self::assertNull( ( new Stripe() )->normalize_event( $payload ) );
	}

	/**
	 * The checkout normalizer must capture payment_intent as provider_ref so
	 * the secondary lookup has something to resolve against.
	 */
	public function test_checkout_normalizer_captures_payment_intent(): void {
		$payload = array(
			'id'   => 'evt_co',
			'type' => 'checkout.session.completed',
			'data' => array(
				'object' => array(
					'id'             => 'cs_new',
					'payment_status' => 'paid',
					'amount_total'   => 800,
					'currency'       => 'usd',
					'payment_intent' => 'pi_new',
				),
			),
		);

		$event = ( new Stripe() )->normalize_event( $payload );

		self::assertInstanceOf( Gateway_Event::class, $event );
		self::assertSame( Gateway_Event::TYPE_CHECKOUT_COMPLETED, $event->type );
		self::assertSame( 'cs_new', $event->session_id );
		self::assertSame( 'pi_new', $event->provider_ref, 'Checkout event must carry payment_intent for later refund linkage.' );
	}
}
