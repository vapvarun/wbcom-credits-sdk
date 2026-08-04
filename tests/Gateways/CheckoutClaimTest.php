<?php
/**
 * Synchronous redirect-claim tests (1.6.0).
 *
 * Before 1.6.0 the ONLY crediting path was the provider webhook. A site
 * owner who never configured one (or whose site the provider could not
 * reach — local, staging, firewalled) captured real payments and granted
 * nothing: the worst failure a payment feature can have, found live as
 * WB Ad Manager Basecamp card 10134503233. These tests lock the claim
 * path: Stripe's session verification, the credit grant, and — most
 * critically — the session-scoped idempotency that makes a claim racing
 * a webhook credit exactly once.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Idempotency;
use Wbcom\Credits\Gateways\Pending_Checkouts;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class CheckoutClaimTest extends TestCase {

	private const SLUG   = 'claim-plug';
	private const PREFIX = 'clpg';

	private const USER_ID      = 7;
	private const CREDITS      = 100;
	private const AMOUNT_CENTS = 1000; // $10.00
	private const CURRENCY     = 'USD';
	private const SESSION_ID   = 'cs_claim_1';

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$balance_cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$balance_cache->setAccessible( true );
		$balance_cache->setValue( null, array() );

		global $wbcom_credits_test_hooks, $wbcom_credits_test_http;
		$wbcom_credits_test_hooks = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_http  = array();

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

		add_filter( 'wbcom_credits_active_slug', static fn() => self::SLUG, 99 );

		// Stripe settings with a secret key so retrieve_checkout_event()
		// gets past the credentials guard.
		update_option(
			'wbcom_credits_gateway_settings_' . self::SLUG,
			array(
				Stripe::ID => array(
					'enabled'         => '1',
					'mode'            => 'test',
					'secret_key_test' => 'sk_test_stub',
				),
			)
		);

		Pending_Checkouts::put(
			self::SLUG,
			self::SESSION_ID,
			array(
				'gateway'     => Stripe::ID,
				'user_id'     => self::USER_ID,
				'credits'     => self::CREDITS,
				'price_cents' => self::AMOUNT_CENTS,
				'currency'    => self::CURRENCY,
			)
		);
	}

	/**
	 * Queue the Stripe session-retrieval response the claim will perform.
	 *
	 * @param array<string, mixed> $overrides Session field overrides.
	 */
	private function stub_session_lookup( array $overrides = array() ): void {
		global $wbcom_credits_test_http;

		$session = array_merge(
			array(
				'id'             => self::SESSION_ID,
				'payment_status' => 'paid',
				'amount_total'   => self::AMOUNT_CENTS,
				'currency'       => strtolower( self::CURRENCY ),
				'payment_intent' => 'pi_claim_1',
			),
			$overrides
		);

		$url = 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( self::SESSION_ID );

		$wbcom_credits_test_http[ 'GET ' . $url ] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $session ),
		);
	}

	public function test_claim_credits_a_paid_session_without_any_webhook(): void {
		$this->stub_session_lookup();

		$response = ( new Stripe() )->claim_checkout( self::SLUG, self::SESSION_ID );
		$data     = $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertSame( self::CREDITS, $data['credits'] );
		self::assertSame( self::CREDITS, Credits::get_balance( self::SLUG, self::USER_ID ) );

		// Checkout recorded and pending entry consumed — a later webhook
		// for the same session finds no pending row and cannot re-credit.
		self::assertNotNull( Transaction_Log::find_checkout( self::SLUG, Stripe::ID, self::SESSION_ID ) );
		self::assertNull( Pending_Checkouts::get( self::SLUG, self::SESSION_ID ) );
	}

	public function test_unpaid_session_stays_pending_and_grants_nothing(): void {
		$this->stub_session_lookup( array( 'payment_status' => 'unpaid' ) );

		$response = ( new Stripe() )->claim_checkout( self::SLUG, self::SESSION_ID );

		self::assertSame( 202, $response->get_status() );
		self::assertTrue( $response->get_data()['pending'] );
		self::assertSame( 0, Credits::get_balance( self::SLUG, self::USER_ID ) );
		self::assertNotNull( Pending_Checkouts::get( self::SLUG, self::SESSION_ID ), 'Pending entry must survive for the webhook to finish later.' );
	}

	public function test_amount_tampering_between_checkout_and_claim_is_rejected(): void {
		$this->stub_session_lookup( array( 'amount_total' => 1 ) );

		$response = ( new Stripe() )->claim_checkout( self::SLUG, self::SESSION_ID );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'amount_or_currency_mismatch', $response->get_data()['error'] );
		self::assertSame( 0, Credits::get_balance( self::SLUG, self::USER_ID ) );
	}

	public function test_claim_after_webhook_does_not_double_credit(): void {
		// Webhook lands first — the normal production ordering.
		( new Stripe() )->handle_webhook(
			self::SLUG,
			array(
				'id'   => 'evt_claim_wh',
				'type' => 'checkout.session.completed',
				'data' => array(
					'object' => array(
						'id'             => self::SESSION_ID,
						'payment_status' => 'paid',
						'amount_total'   => self::AMOUNT_CENTS,
						'currency'       => strtolower( self::CURRENCY ),
						'payment_intent' => 'pi_claim_1',
					),
				),
			)
		);
		self::assertSame( self::CREDITS, Credits::get_balance( self::SLUG, self::USER_ID ) );

		// The buyer's browser then claims the same session.
		$this->stub_session_lookup();
		$response = ( new Stripe() )->claim_checkout( self::SLUG, self::SESSION_ID );

		// Pending entry is gone, so the claim reports unknown_session (the
		// REST controller translates this into "already credited" via the
		// Transaction_Log) — and the balance MUST NOT move again.
		self::assertSame( 404, $response->get_status() );
		self::assertSame( self::CREDITS, Credits::get_balance( self::SLUG, self::USER_ID ) );
	}

	public function test_racing_webhook_and_claim_credit_exactly_once(): void {
		// Simulate the true race: the webhook delivery has claimed its
		// provider-event id and is mid-flight (pending row still present)
		// while the redirect claim reaches the session-idempotency gate
		// first. Whichever caller wins the session key credits; here the
		// claim wins, then the "webhook" continues and must lose.
		$this->stub_session_lookup();
		$claim_response = ( new Stripe() )->claim_checkout( self::SLUG, self::SESSION_ID );
		self::assertSame( 200, $claim_response->get_status() );

		// Re-seed the pending row to model the webhook thread that read it
		// before the claim forgot it, then drive the webhook delivery.
		Pending_Checkouts::put(
			self::SLUG,
			self::SESSION_ID,
			array(
				'gateway'     => Stripe::ID,
				'user_id'     => self::USER_ID,
				'credits'     => self::CREDITS,
				'price_cents' => self::AMOUNT_CENTS,
				'currency'    => self::CURRENCY,
			)
		);
		$webhook_response = ( new Stripe() )->handle_webhook(
			self::SLUG,
			array(
				'id'   => 'evt_claim_race',
				'type' => 'checkout.session.completed',
				'data' => array(
					'object' => array(
						'id'             => self::SESSION_ID,
						'payment_status' => 'paid',
						'amount_total'   => self::AMOUNT_CENTS,
						'currency'       => strtolower( self::CURRENCY ),
						'payment_intent' => 'pi_claim_1',
					),
				),
			)
		);

		self::assertTrue( $webhook_response->get_data()['duplicate'] ?? false, 'The losing path must ack as a duplicate.' );
		self::assertSame( self::CREDITS, Credits::get_balance( self::SLUG, self::USER_ID ), 'Exactly one credit grant across both paths.' );
	}

	public function test_gateway_without_sync_verification_stays_webhook_only(): void {
		$paypal = new \Wbcom\Credits\Gateways\PayPal();

		$response = $paypal->claim_checkout( self::SLUG, self::SESSION_ID );

		self::assertSame( 202, $response->get_status() );
		self::assertTrue( $response->get_data()['pending'] );
		self::assertSame( 0, Credits::get_balance( self::SLUG, self::USER_ID ) );
	}
}
