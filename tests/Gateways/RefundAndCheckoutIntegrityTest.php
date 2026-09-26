<?php
/**
 * 1.7.2 refund and checkout integrity.
 *
 *   - Cumulative refund amounts (Stripe's charge.amount_refunded) revoke only
 *     the part not yet applied, so two partial refunds cannot over-revoke.
 *   - A claim taken for an event whose crediting FAILED is released, so the
 *     provider's retry can still credit a paid session.
 *   - Stripe checkouts no longer send the unfilled {CHECKOUT_SESSION_ID}
 *     metadata placeholder, and charges that carry it resolve by payment intent.
 *   - Pending checkouts are stored per session and still read legacy entries.
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
use Wbcom\Credits\Gateways\Pending_Checkouts;
use Wbcom\Credits\Gateways\Processed_Events;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Gateways\Fixtures\Test_Gateway;
use Wbcom\Credits\Tests\Support\FakeWpdb;

require_once __DIR__ . '/Fixtures/Test_Gateway.php';

final class RefundAndCheckoutIntegrityTest extends TestCase {

	private const SLUG   = 'integrity-plug';
	private const PREFIX = 'itpg';

	protected function setUp(): void {
		global $wpdb, $wbcom_credits_test_hooks, $wbcom_credits_test_options;
		$wpdb                       = new FakeWpdb();
		$wbcom_credits_test_hooks   = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_options = array();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );

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
	}

	private function seed_checkout(): void {
		Transaction_Log::insert_checkout(
			array(
				'slug'           => self::SLUG,
				'gateway'        => 'teststripe',
				'session_id'     => 'cs_int_1',
				'payment_intent' => 'pi_int_1',
				'event_id'       => 'evt_checkout_int',
				'user_id'        => 7,
				'credits'        => 100,
				'amount_cents'   => 1000,
				'currency'       => 'USD',
				'ledger_id'      => 1,
			)
		);
		Credits::topup( self::SLUG, 7, 100, 'seed' );
	}

	private function balance(): int {
		$cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );
		return Credits::get_balance( self::SLUG, 7 );
	}

	/**
	 * Two $3 refunds on a $10 / 100-credit charge arrive as cumulative 300 and
	 * 600. Before 1.7.2 the second was treated as a new $6 refund: 90 credits
	 * revoked instead of 60.
	 */
	public function test_cumulative_refunds_revoke_only_the_new_part(): void {
		$this->seed_checkout();
		$gateway = new Test_Gateway();

		foreach ( array( 'evt_r1' => 300, 'evt_r2' => 600 ) as $event_id => $cumulative ) {
			$gateway->handle_webhook(
				self::SLUG,
				array(
					'_event' => array(
						'type'         => Gateway_Event::TYPE_REFUND,
						'event_id'     => $event_id,
						'session_id'   => 'cs_int_1',
						'amount_cents' => $cumulative,
						'currency'     => 'USD',
						'cumulative'   => true,
					),
				)
			);
		}

		self::assertSame( 40, $this->balance(), '60% refunded must leave 40 of 100 credits.' );
	}

	/**
	 * A checkout event that arrives before (or after losing) its pending entry
	 * fails with unknown_session. Its claim used to stay taken, so the retry
	 * was acked as a duplicate and the paid session was never credited.
	 */
	public function test_failed_checkout_releases_its_claim_so_a_retry_credits(): void {
		$gateway = new Test_Gateway();
		$payload = array(
			'_event' => array(
				'type'         => Gateway_Event::TYPE_CHECKOUT_COMPLETED,
				'event_id'     => 'evt_paid_1',
				'session_id'   => 'cs_late',
				'amount_cents' => 1000,
				'currency'     => 'USD',
			),
		);

		$first = $gateway->handle_webhook( self::SLUG, $payload );
		self::assertSame( 404, $first->get_status() );
		self::assertFalse( Processed_Events::exists( self::SLUG, $gateway->get_id(), 'evt_paid_1' ), 'Claim must be released on failure.' );

		Pending_Checkouts::put(
			self::SLUG,
			'cs_late',
			array(
				'gateway'     => $gateway->get_id(),
				'user_id'     => 7,
				'credits'     => 100,
				'price_cents' => 1000,
				'currency'    => 'USD',
			)
		);

		$retry = $gateway->handle_webhook( self::SLUG, $payload );
		self::assertSame( 200, $retry->get_status() );
		self::assertSame( 100, $this->balance(), 'The retried delivery must credit the session.' );
	}

	/**
	 * Stripe fills {CHECKOUT_SESSION_ID} only in success_url. Sending it as
	 * PaymentIntent metadata stored the literal text on every charge.
	 */
	public function test_stripe_checkout_sends_no_placeholder_metadata(): void {
		global $wbcom_credits_test_http, $wbcom_credits_test_http_log;
		$wbcom_credits_test_http_log = array();
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
		$wbcom_credits_test_http = array(
			'POST https://api.stripe.com/v1/checkout/sessions' => array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						'id'  => 'cs_meta_1',
						'url' => 'https://checkout.stripe.com/pay/cs_meta_1',
					)
				),
			),
		);

		( new Stripe() )->create_checkout( self::SLUG, 7, 100, 1000, 'USD', null );

		$request = end( $wbcom_credits_test_http_log );
		parse_str( (string) ( $request['args']['body'] ?? '' ), $body );

		self::assertArrayNotHasKey( 'wbcom_session', (array) ( $body['payment_intent_data']['metadata'] ?? array() ) );
		self::assertStringContainsString( '{CHECKOUT_SESSION_ID}', urldecode( (string) ( $body['success_url'] ?? '' ) ), 'success_url keeps the placeholder Stripe does fill.' );
	}

	/**
	 * A charge created before 1.7.2 carries the literal placeholder; it must
	 * resolve through the payment intent, and be flagged cumulative.
	 */
	public function test_placeholder_metadata_resolves_by_payment_intent(): void {
		add_filter( 'wbcom_credits_active_slug', static fn() => self::SLUG, 99 );
		Transaction_Log::insert_checkout(
			array(
				'slug'           => self::SLUG,
				'gateway'        => Stripe::ID,
				'session_id'     => 'cs_real',
				'payment_intent' => 'pi_real',
				'event_id'       => 'evt_co',
				'user_id'        => 7,
				'credits'        => 100,
				'amount_cents'   => 1000,
				'currency'       => 'USD',
				'ledger_id'      => 1,
			)
		);

		$event = ( new Stripe() )->normalize_event(
			array(
				'id'   => 'evt_ref_ph',
				'type' => 'charge.refunded',
				'data' => array(
					'object' => array(
						'payment_intent'  => 'pi_real',
						'amount_refunded' => 300,
						'currency'        => 'usd',
						'metadata'        => array( 'wbcom_session' => '{CHECKOUT_SESSION_ID}' ),
					),
				),
			)
		);

		self::assertInstanceOf( Gateway_Event::class, $event );
		self::assertSame( 'cs_real', $event->session_id );
		self::assertTrue( $event->amount_is_cumulative );
	}

	/**
	 * Entries written by a pre-1.7.2 copy live in the shared option and must
	 * still be read and removable after the upgrade.
	 */
	public function test_legacy_shared_entries_are_still_read_and_forgotten(): void {
		update_option(
			'wbcom_credits_pending_checkouts_' . self::SLUG,
			array(
				'cs_legacy' => array(
					'gateway'     => 'stripe',
					'user_id'     => 7,
					'credits'     => 50,
					'price_cents' => 500,
					'currency'    => 'USD',
					'expires_at'  => time() + 3600,
				),
			)
		);

		$entry = Pending_Checkouts::get( self::SLUG, 'cs_legacy' );
		self::assertIsArray( $entry );
		self::assertSame( 50, $entry['credits'] );

		Pending_Checkouts::forget( self::SLUG, 'cs_legacy' );
		self::assertNull( Pending_Checkouts::get( self::SLUG, 'cs_legacy' ) );
		self::assertFalse( get_option( 'wbcom_credits_pending_checkouts_' . self::SLUG, false ), 'The emptied legacy option is deleted.' );
	}

	/**
	 * Two checkouts stored back to back each keep their own entry.
	 */
	public function test_each_session_has_its_own_entry(): void {
		foreach ( array( 'cs_a' => 10, 'cs_b' => 20 ) as $sid => $credits ) {
			Pending_Checkouts::put(
				self::SLUG,
				$sid,
				array(
					'gateway'     => 'stripe',
					'user_id'     => 7,
					'credits'     => $credits,
					'price_cents' => $credits * 10,
					'currency'    => 'USD',
				)
			);
		}

		Pending_Checkouts::forget( self::SLUG, 'cs_a' );

		self::assertNull( Pending_Checkouts::get( self::SLUG, 'cs_a' ) );
		self::assertSame( 20, Pending_Checkouts::get( self::SLUG, 'cs_b' )['credits'] ?? null );
	}
}
