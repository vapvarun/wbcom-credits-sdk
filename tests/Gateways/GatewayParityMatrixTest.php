<?php
/**
 * Gateway parity matrix test (T22).
 *
 * Stripe and PayPal each speak a different provider event vocabulary, but
 * both are driven through the SAME shared orchestration in
 * {@see \Wbcom\Credits\Gateways\Abstract_Gateway} — normalize_event() is the
 * only place they legitimately diverge. This test drives equivalent
 * checkout-completed and refund events through the real `Stripe` and
 * `PayPal` gateway classes (not a test fixture) via the public
 * `handle_webhook()` entry point, and asserts the SHARED domain outcome is
 * identical for both providers:
 *
 *   - purchase (checkout completed): same `Credits::topup` amount and a
 *     `Transaction_Log` checkout row with the same shape (credits,
 *     amount_cents, currency) — gateway-specific identifiers (session id,
 *     event id, payment_intent) legitimately differ and are excluded from
 *     the comparison.
 *   - refund: same `Credits::adjust`/revoke outcome, a `Transaction_Log`
 *     refund row with the same shape, and both fire `wbcom_credits_refunded`
 *     with the same `($slug, $user_id, $amount, $context)` signature
 *     (excluding the gateway-specific context values).
 *
 * Where the two providers legitimately differ — raw payload decoding in
 * normalize_event() — is upstream of the shared path and out of scope here;
 * see StripeRefundLinkageTest / PayPalRefundLinkageTest for that surface.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Pending_Checkouts;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Gateways\PayPal;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class GatewayParityMatrixTest extends TestCase {

	private const SLUG   = 'parity-plug';
	private const PREFIX = 'prpg';

	private const CREDITS      = 100;
	private const AMOUNT_CENTS = 1000; // $10.00
	private const CURRENCY     = 'USD';

	private const REFUND_CENTS = 400; // $4.00 partial refund.

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$balance_cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$balance_cache->setAccessible( true );
		$balance_cache->setValue( null, array() );

		global $wbcom_credits_test_hooks;
		$wbcom_credits_test_hooks = array( 'actions' => array(), 'filters' => array() );

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);

		\Wbcom\Credits\Ledger::maybe_create_table( self::PREFIX );
		Transaction_Log::maybe_create_table( self::PREFIX );
		Processed_Events::maybe_create_table( self::PREFIX );

		// Resolve active slug to ours for any active_slug()-dependent lookups
		// (e.g. PayPal's capture-id fallback resolution).
		add_filter( 'wbcom_credits_active_slug', static fn() => self::SLUG, 99 );
	}

	/**
	 * Drive Stripe through a checkout.session.completed event via the real
	 * `Stripe` gateway's public `handle_webhook()` entry point.
	 *
	 * @return array{checkout:array<string,mixed>, checkout_row:array<string,mixed>|null, balance:int}
	 */
	private function stripe_checkout( int $user_id ): array {
		$session_id     = 'cs_stripe_1';
		$payment_intent = 'pi_stripe_1';

		Pending_Checkouts::put(
			self::SLUG,
			$session_id,
			array(
				'gateway'     => Stripe::ID,
				'user_id'     => $user_id,
				'credits'     => self::CREDITS,
				'price_cents' => self::AMOUNT_CENTS,
				'currency'    => self::CURRENCY,
			)
		);

		$checkout_response = ( new Stripe() )->handle_webhook(
			self::SLUG,
			array(
				'id'   => 'evt_stripe_checkout',
				'type' => 'checkout.session.completed',
				'data' => array(
					'object' => array(
						'id'             => $session_id,
						'payment_status' => 'paid',
						'amount_total'   => self::AMOUNT_CENTS,
						'currency'       => strtolower( self::CURRENCY ),
						'payment_intent' => $payment_intent,
					),
				),
			)
		);

		return array(
			'checkout'     => $checkout_response->get_data(),
			'checkout_row' => Transaction_Log::find_checkout( self::SLUG, Stripe::ID, $session_id ),
			'balance'      => Credits::get_balance( self::SLUG, $user_id ),
		);
	}

	/**
	 * Refund a previously-completed Stripe checkout (must be preceded by a
	 * {@see stripe_checkout()} call for the same user in this test).
	 *
	 * @return array{refund:array<string,mixed>, refund_fired:array, balance:int}
	 */
	private function stripe_refund( int $user_id ): array {
		$session_id     = 'cs_stripe_1';
		$payment_intent = 'pi_stripe_1';

		$fired = array();
		add_action(
			'wbcom_credits_refunded',
			static function ( $slug, $uid, $amount, $context = array() ) use ( &$fired ): void {
				$fired[] = array( $slug, $uid, $amount, $context );
			},
			10,
			4
		);

		$refund_response = ( new Stripe() )->handle_webhook(
			self::SLUG,
			array(
				'id'   => 'evt_stripe_refund',
				'type' => 'charge.refunded',
				'data' => array(
					'object' => array(
						'payment_intent'  => $payment_intent,
						'amount_refunded' => self::REFUND_CENTS,
						'currency'        => strtolower( self::CURRENCY ),
						'metadata'        => array( 'wbcom_session' => $session_id ),
					),
				),
			)
		);

		// Clear the just-added listener so a later refund call in the same
		// test method (a different provider) starts with a clean
		// 'wbcom_credits_refunded' registry instead of accumulating closures.
		global $wbcom_credits_test_hooks;
		unset( $wbcom_credits_test_hooks['actions']['wbcom_credits_refunded'] );

		return array(
			'refund'       => $refund_response->get_data(),
			'refund_fired' => $fired,
			'balance'      => Credits::get_balance( self::SLUG, $user_id ),
		);
	}

	/**
	 * Drive PayPal through a PAYMENT.CAPTURE.COMPLETED event via the real
	 * `PayPal` gateway's public `handle_webhook()` entry point, with
	 * economically-equivalent values to {@see stripe_checkout()}.
	 *
	 * @return array{checkout:array<string,mixed>, checkout_row:array<string,mixed>|null, balance:int}
	 */
	private function paypal_checkout( int $user_id ): array {
		$order_id   = 'PAYPAL_ORDER_1';
		$capture_id = 'CAPTURE_PP_1';

		Pending_Checkouts::put(
			self::SLUG,
			$order_id,
			array(
				'gateway'     => PayPal::ID,
				'user_id'     => $user_id,
				'credits'     => self::CREDITS,
				'price_cents' => self::AMOUNT_CENTS,
				'currency'    => self::CURRENCY,
			)
		);

		$checkout_response = ( new PayPal() )->handle_webhook(
			self::SLUG,
			array(
				'id'         => 'evt_paypal_checkout',
				'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
				'resource'   => array(
					'id'                 => $capture_id,
					'custom_id'          => wp_json_encode(
						array(
							'slug'    => self::SLUG,
							'user_id' => $user_id,
							'credits' => self::CREDITS,
							'session' => $order_id,
						)
					),
					'amount'             => array(
						'value'         => number_format( self::AMOUNT_CENTS / 100, 2, '.', '' ),
						'currency_code' => self::CURRENCY,
					),
					'supplementary_data' => array( 'related_ids' => array( 'order_id' => $order_id ) ),
				),
			)
		);

		return array(
			'checkout'     => $checkout_response->get_data(),
			'checkout_row' => Transaction_Log::find_checkout( self::SLUG, PayPal::ID, $order_id ),
			'balance'      => Credits::get_balance( self::SLUG, $user_id ),
		);
	}

	/**
	 * Refund a previously-completed PayPal checkout (must be preceded by a
	 * {@see paypal_checkout()} call for the same user in this test).
	 *
	 * @return array{refund:array<string,mixed>, refund_fired:array, balance:int}
	 */
	private function paypal_refund( int $user_id ): array {
		$order_id = 'PAYPAL_ORDER_1';

		$fired = array();
		add_action(
			'wbcom_credits_refunded',
			static function ( $slug, $uid, $amount, $context = array() ) use ( &$fired ): void {
				$fired[] = array( $slug, $uid, $amount, $context );
			},
			10,
			4
		);

		$refund_response = ( new PayPal() )->handle_webhook(
			self::SLUG,
			array(
				'id'         => 'evt_paypal_refund',
				'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
				'resource'   => array(
					'custom_id' => wp_json_encode( array( 'session' => $order_id ) ),
					'amount'    => array(
						'value'         => number_format( self::REFUND_CENTS / 100, 2, '.', '' ),
						'currency_code' => self::CURRENCY,
					),
				),
			)
		);

		global $wbcom_credits_test_hooks;
		unset( $wbcom_credits_test_hooks['actions']['wbcom_credits_refunded'] );

		return array(
			'refund'       => $refund_response->get_data(),
			'refund_fired' => $fired,
			'balance'      => Credits::get_balance( self::SLUG, $user_id ),
		);
	}

	public function test_checkout_completed_produces_identical_domain_outcome(): void {
		$stripe = $this->stripe_checkout( 101 );
		$paypal = $this->paypal_checkout( 102 );

		// Same topup amount reported by the shared orchestration.
		self::assertSame( self::CREDITS, $stripe['checkout']['credits'] );
		self::assertSame( self::CREDITS, $paypal['checkout']['credits'] );
		self::assertSame( $stripe['checkout']['credits'], $paypal['checkout']['credits'], 'Stripe and PayPal must top up the same credit amount for equivalent input.' );

		// Both actually landed the credits.
		self::assertSame( self::CREDITS, $stripe['balance'] );
		self::assertSame( self::CREDITS, $paypal['balance'] );

		// Transaction_Log checkout rows have the SAME SHAPE for the fields
		// that are gateway-agnostic domain outcomes. session_id, event_id,
		// gateway, and payment_intent are legitimately provider-specific and
		// excluded from the comparison.
		self::assertNotNull( $stripe['checkout_row'] );
		self::assertNotNull( $paypal['checkout_row'] );
		foreach ( array( 'kind', 'credits', 'amount_cents', 'currency', 'refunded_cents' ) as $field ) {
			self::assertSame(
				$stripe['checkout_row'][ $field ],
				$paypal['checkout_row'][ $field ],
				"Transaction_Log checkout row field '{$field}' must match between Stripe and PayPal."
			);
		}
	}

	public function test_refund_produces_identical_domain_outcome(): void {
		$this->stripe_checkout( 201 );
		$stripe = $this->stripe_refund( 201 );

		$this->paypal_checkout( 202 );
		$paypal = $this->paypal_refund( 202 );

		$expected_revoked = (int) floor( self::CREDITS * self::REFUND_CENTS / self::AMOUNT_CENTS );

		// Same prorated credits_revoked reported by the shared orchestration.
		self::assertSame( $expected_revoked, $stripe['refund']['credits_revoked'] );
		self::assertSame( $expected_revoked, $paypal['refund']['credits_revoked'] );
		self::assertSame( $stripe['refund']['credits_revoked'], $paypal['refund']['credits_revoked'] );

		// Same resulting balance after the partial refund.
		self::assertSame( self::CREDITS - $expected_revoked, $stripe['balance'] );
		self::assertSame( self::CREDITS - $expected_revoked, $paypal['balance'] );
		self::assertSame( $stripe['balance'], $paypal['balance'] );

		// Both fire the generic wbcom_credits_refunded action exactly once,
		// with the same ($slug, $user_id, $amount, $context) shape.
		self::assertCount( 1, $stripe['refund_fired'] );
		self::assertCount( 1, $paypal['refund_fired'] );

		[ $s_slug, , $s_amount, $s_context ] = $stripe['refund_fired'][0];
		[ $p_slug, , $p_amount, $p_context ] = $paypal['refund_fired'][0];

		self::assertSame( self::SLUG, $s_slug );
		self::assertSame( self::SLUG, $p_slug );
		self::assertSame( $expected_revoked, $s_amount );
		self::assertSame( $expected_revoked, $p_amount );
		self::assertSame( $s_amount, $p_amount, 'Revoked-amount arg must be identical for equivalent refunds.' );

		// Context shape parity: same keys, same reason/item_id values. gateway,
		// session_id, provider_ref, and ledger_id are legitimately
		// provider-specific and excluded from the value comparison.
		self::assertSame( array_keys( $s_context ), array_keys( $p_context ), 'Refund context must expose the same key set for both providers.' );
		self::assertSame( 'gateway_refund', $s_context['reason'] );
		self::assertSame( 'gateway_refund', $p_context['reason'] );
		self::assertSame( 0, $s_context['item_id'], 'Gateway refunds are not item-scoped for either provider.' );
		self::assertSame( 0, $p_context['item_id'] );

		// Transaction_Log refund rows have the same shape for the
		// gateway-agnostic fields.
		$stripe_refund_row = Transaction_Log::find_checkout( self::SLUG, Stripe::ID, 'cs_stripe_1' );
		$paypal_refund_row = Transaction_Log::find_checkout( self::SLUG, PayPal::ID, 'PAYPAL_ORDER_1' );
		self::assertSame( $stripe_refund_row['refunded_cents'], $paypal_refund_row['refunded_cents'], 'refunded_cents must match between Stripe and PayPal for the same refund amount.' );
	}
}
