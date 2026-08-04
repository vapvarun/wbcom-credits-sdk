<?php
/**
 * Gateway crediting on money-mode consumers.
 *
 * 1.5.1 fixed adapter mappings feeding MAJOR-unit values into the
 * integer ledger API on money consumers — but missed the gateway
 * orchestrator: process_checkout_completed() fed the purchased credit
 * count straight into Credits::topup(), so every Stripe/PayPal purchase
 * on a money consumer credited 1/minor-factor of what the buyer paid
 * for ($10 buying 100 credits landed as 1). Found live on WB Ad Manager
 * Pro while verifying the 1.6.0 redirect claim. The refund path had the
 * mirror bug via Credits::adjust().
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
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class GatewayMoneyModeTest extends TestCase {

	private const SLUG   = 'money-plug';
	private const PREFIX = 'mnpg';

	private const USER_ID      = 5;
	private const CREDITS      = 100;   // Major units on a money consumer.
	private const AMOUNT_CENTS = 1000;  // What the buyer paid ($10.00).
	private const CURRENCY     = 'USD';
	private const SESSION_ID   = 'cs_money_1';

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
				'money'   => array( 'currency' => self::CURRENCY ),
			)
		);

		\Wbcom\Credits\Ledger::maybe_create_table( self::PREFIX );
		Transaction_Log::maybe_create_table( self::PREFIX );
		Processed_Events::maybe_create_table( self::PREFIX );

		add_filter( 'wbcom_credits_active_slug', static fn() => self::SLUG, 99 );

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

	private function deliver_checkout_webhook(): void {
		( new Stripe() )->handle_webhook(
			self::SLUG,
			array(
				'id'   => 'evt_money_checkout',
				'type' => 'checkout.session.completed',
				'data' => array(
					'object' => array(
						'id'             => self::SESSION_ID,
						'payment_status' => 'paid',
						'amount_total'   => self::AMOUNT_CENTS,
						'currency'       => strtolower( self::CURRENCY ),
						'payment_intent' => 'pi_money_1',
					),
				),
			)
		);
	}

	public function test_checkout_credits_money_consumer_in_ledger_minor_units(): void {
		$this->deliver_checkout_webhook();

		// 100 credits on a USD money consumer = $100.00 = 10000 minor units
		// in the ledger — NOT 100, which is what the pre-fix path wrote.
		self::assertSame( self::CREDITS * 100, Credits::get_balance( self::SLUG, self::USER_ID ) );
		self::assertSame( (float) self::CREDITS, Credits::balance_money( self::SLUG, self::USER_ID ) );
	}

	public function test_full_refund_revokes_the_same_money_amount(): void {
		$this->deliver_checkout_webhook();

		( new Stripe() )->handle_webhook(
			self::SLUG,
			array(
				'id'   => 'evt_money_refund',
				'type' => 'charge.refunded',
				'data' => array(
					'object' => array(
						'payment_intent'  => 'pi_money_1',
						'amount_refunded' => self::AMOUNT_CENTS,
						'currency'        => strtolower( self::CURRENCY ),
						'metadata'        => array( 'wbcom_session' => self::SESSION_ID ),
					),
				),
			)
		);

		self::assertSame( 0, Credits::get_balance( self::SLUG, self::USER_ID ), 'A full refund must return the balance to zero, not leave 99% behind.' );
	}
}
