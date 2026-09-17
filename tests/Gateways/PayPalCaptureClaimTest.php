<?php
/**
 * PayPal capture-on-claim tests.
 *
 * PayPal orders are created with intent CAPTURE, and an approved order is not
 * paid until someone calls POST /v2/checkout/orders/{id}/capture. Nothing did:
 * the redirect claim fell back to "202 pending" and the only crediting path was
 * the PAYMENT.CAPTURE.COMPLETED webhook - which PayPal only sends AFTER a
 * capture. So a buyer approved the payment, no money moved, and no credits
 * landed (WB Listora QA, 2026-09-17). These tests lock the capture on both
 * paths (redirect claim and CHECKOUT.ORDER.APPROVED webhook) and that the two
 * paths plus the later capture webhook credit exactly once.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\PayPal;
use Wbcom\Credits\Gateways\Pending_Checkouts;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class PayPalCaptureClaimTest extends TestCase {

	private const SLUG     = 'paypal-plug';
	private const PREFIX   = 'pppg';
	private const USER_ID  = 9;
	private const CREDITS  = 100;
	private const CENTS    = 999;
	private const CURRENCY = 'USD';
	private const ORDER_ID = '5O190127TN364715T';
	private const BASE     = 'https://api-m.sandbox.paypal.com';

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_hooks, $wbcom_credits_test_http, $wbcom_credits_test_http_log, $wbcom_credits_test_transients;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$balance_cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$balance_cache->setAccessible( true );
		$balance_cache->setValue( null, array() );

		$wbcom_credits_test_hooks      = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_http       = array();
		$wbcom_credits_test_http_log   = array();
		$wbcom_credits_test_transients = array();

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

		update_option(
			'wbcom_credits_gateway_settings_' . self::SLUG,
			array(
				PayPal::ID => array(
					'enabled'       => '1',
					'mode'          => 'sandbox',
					'client_id'     => 'client_stub',
					'client_secret' => 'secret_stub',
					'webhook_id'    => 'wh_stub',
				),
			)
		);

		Pending_Checkouts::put(
			self::SLUG,
			self::ORDER_ID,
			array(
				'gateway'     => PayPal::ID,
				'user_id'     => self::USER_ID,
				'credits'     => self::CREDITS,
				'price_cents' => self::CENTS,
				'currency'    => self::CURRENCY,
			)
		);

		$wbcom_credits_test_http[ 'POST ' . self::BASE . '/v1/oauth2/token' ] = self::json( array( 'access_token' => 'tok_stub' ) );
	}

	/**
	 * @param array<string, mixed> $body Response body.
	 * @return array<string, mixed>
	 */
	private static function json( array $body, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) json_encode( $body ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function capture( string $status = 'COMPLETED' ): array {
		return array(
			'id'     => 'CAP123',
			'status' => $status,
			'amount' => array(
				'currency_code' => self::CURRENCY,
				'value'         => '9.99',
			),
		);
	}

	private function stub_order( string $status, array $captures = array() ): void {
		global $wbcom_credits_test_http;
		$order = array(
			'id'             => self::ORDER_ID,
			'status'         => $status,
			'purchase_units' => array(
				array(
					'payments' => array( 'captures' => $captures ),
				),
			),
		);
		$wbcom_credits_test_http[ 'GET ' . self::BASE . '/v2/checkout/orders/' . self::ORDER_ID ] = self::json( $order );
	}

	private function stub_capture_call( string $capture_status = 'COMPLETED' ): void {
		global $wbcom_credits_test_http;
		$wbcom_credits_test_http[ 'POST ' . self::BASE . '/v2/checkout/orders/' . self::ORDER_ID . '/capture' ] = self::json(
			array(
				'id'             => self::ORDER_ID,
				'status'         => 'COMPLETED' === $capture_status ? 'COMPLETED' : 'APPROVED',
				'purchase_units' => array(
					array( 'payments' => array( 'captures' => array( self::capture( $capture_status ) ) ) ),
				),
			),
			201
		);
	}

	private static function capture_calls(): array {
		global $wbcom_credits_test_http_log;
		return array_values(
			array_filter(
				(array) $wbcom_credits_test_http_log,
				static fn( $entry ) => str_ends_with( (string) $entry['url'], '/capture' )
			)
		);
	}

	public function test_claim_captures_an_approved_order_and_credits(): void {
		$this->stub_order( 'APPROVED' );
		$this->stub_capture_call();

		$response = ( new PayPal() )->claim_checkout( self::SLUG, self::ORDER_ID );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( self::CREDITS, Credits::get_balance( self::SLUG, self::USER_ID ) );
		self::assertCount( 1, self::capture_calls() );

		// Idempotent capture: PayPal-Request-Id makes a retried capture safe.
		$headers = self::capture_calls()[0]['args']['headers'];
		self::assertSame( 'capture-' . self::ORDER_ID, $headers['PayPal-Request-Id'] ?? '' );

		// The capture id is recorded so a later refund webhook resolves this checkout.
		$row = Transaction_Log::find_checkout( self::SLUG, PayPal::ID, self::ORDER_ID );
		self::assertNotNull( $row );
	}

	public function test_claim_does_not_capture_an_order_the_buyer_has_not_approved(): void {
		$this->stub_order( 'PAYER_ACTION_REQUIRED' );

		$response = ( new PayPal() )->claim_checkout( self::SLUG, self::ORDER_ID );

		self::assertSame( 202, $response->get_status() );
		self::assertCount( 0, self::capture_calls() );
		self::assertSame( 0, Credits::get_balance( self::SLUG, self::USER_ID ) );
	}

	public function test_already_captured_order_credits_without_capturing_again(): void {
		$this->stub_order( 'COMPLETED', array( self::capture() ) );

		$response = ( new PayPal() )->claim_checkout( self::SLUG, self::ORDER_ID );

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 0, self::capture_calls() );
		self::assertSame( self::CREDITS, Credits::get_balance( self::SLUG, self::USER_ID ) );
	}

	public function test_pending_capture_is_not_credited(): void {
		$this->stub_order( 'APPROVED' );
		$this->stub_capture_call( 'PENDING' );

		$response = ( new PayPal() )->claim_checkout( self::SLUG, self::ORDER_ID );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 0, Credits::get_balance( self::SLUG, self::USER_ID ) );
	}

	public function test_order_approved_webhook_captures_for_a_buyer_who_never_returned(): void {
		$this->stub_order( 'APPROVED' );
		$this->stub_capture_call();

		$payload = array(
			'id'         => 'WH-APPROVED-1',
			'event_type' => 'CHECKOUT.ORDER.APPROVED',
			'resource'   => array(
				'id'             => self::ORDER_ID,
				'status'         => 'APPROVED',
				'purchase_units' => array(
					array( 'custom_id' => (string) json_encode( array( 'slug' => self::SLUG, 'user_id' => self::USER_ID, 'credits' => self::CREDITS, 'session' => self::ORDER_ID ) ) ),
				),
			),
		);

		$response = ( new PayPal() )->handle_webhook( self::SLUG, $payload );

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, self::capture_calls() );
		self::assertSame( self::CREDITS, Credits::get_balance( self::SLUG, self::USER_ID ) );
	}

	public function test_claim_then_capture_webhook_credits_exactly_once(): void {
		$this->stub_order( 'APPROVED' );
		$this->stub_capture_call();

		( new PayPal() )->claim_checkout( self::SLUG, self::ORDER_ID );

		$capture_webhook = array(
			'id'         => 'WH-CAPTURE-1',
			'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
			'resource'   => array_merge(
				self::capture(),
				array( 'supplementary_data' => array( 'related_ids' => array( 'order_id' => self::ORDER_ID ) ) )
			),
		);
		( new PayPal() )->handle_webhook( self::SLUG, $capture_webhook );

		self::assertSame( self::CREDITS, Credits::get_balance( self::SLUG, self::USER_ID ) );
	}

	public function test_capture_webhook_resolves_the_order_from_a_stamped_custom_id(): void {
		$event = ( new PayPal() )->normalize_event(
			array(
				'id'         => 'WH-CAPTURE-2',
				'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
				'resource'   => array_merge(
					self::capture(),
					array( 'custom_id' => (string) json_encode( array( 'slug' => self::SLUG, 'user_id' => self::USER_ID, 'credits' => self::CREDITS, 'session' => self::ORDER_ID ) ) )
				),
			)
		);

		self::assertNotNull( $event );
		self::assertSame( self::ORDER_ID, $event->session_id );
	}
}
