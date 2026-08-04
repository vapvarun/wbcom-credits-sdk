<?php
/**
 * Checkout success/cancel URLs carry the gateway marker on EVERY branch.
 *
 * Regression guard for the seam behind WB Ad Manager card 10134503233:
 * the wbcom_credits/gateway/credits params were appended only when the
 * caller passed a return_url. A consumer relying on the settings or
 * home_url fallback got a success URL with no gateway marker, so its
 * return handler could not tell which gateway to claim the session
 * against. The params now attach to whichever base wins.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Pending_Checkouts;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class CheckoutReturnUrlTest extends TestCase {

	private const SLUG = 'returnurl-plug';

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		global $wbcom_credits_test_hooks, $wbcom_credits_test_http, $wbcom_credits_test_http_log;
		$wbcom_credits_test_hooks    = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_http_log = array();

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => 'rupg',
				'version' => '1.0.0',
			)
		);

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
						'id'  => 'cs_ru_1',
						'url' => 'https://checkout.stripe.com/pay/cs_ru_1',
					)
				),
			),
		);
	}

	/**
	 * @return array<string, string> Parsed query args of the posted success_url.
	 */
	private function checkout_success_query( ?string $return_url ): array {
		global $wbcom_credits_test_http_log;

		( new Stripe() )->create_checkout( self::SLUG, 7, 100, 1000, 'USD', $return_url );

		$request = end( $wbcom_credits_test_http_log );
		parse_str( (string) ( $request['args']['body'] ?? '' ), $body );
		$success = (string) ( $body['success_url'] ?? '' );

		parse_str( (string) parse_url( $success, PHP_URL_QUERY ), $query );

		Pending_Checkouts::forget( self::SLUG, 'cs_ru_1' );

		return array_map( 'strval', $query );
	}

	public function test_return_url_branch_carries_gateway_marker(): void {
		$query = $this->checkout_success_query( 'https://example.test/dashboard/?tab=wallet' );

		self::assertSame( 'success', $query['wbcom_credits'] );
		self::assertSame( Stripe::ID, $query['gateway'] );
		self::assertSame( '100', $query['credits'] );
	}

	public function test_consumer_registered_return_url_resolves(): void {
		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		Registry::instance()->register(
			array(
				'slug'       => 'walleted-plug',
				'prefix'     => 'wlpg',
				'return_url' => static fn (): string => 'https://example.test/dashboard/?tab=wallet',
			)
		);
		Registry::instance()->register(
			array(
				'slug'   => 'plain-plug',
				'prefix' => 'plpg',
			)
		);

		self::assertSame(
			'https://example.test/dashboard/?tab=wallet',
			Registry::instance()->return_url_for( 'walleted-plug' ),
			'A consumer-registered wallet page is where its buyers land after checkout.'
		);
		self::assertSame( '', Registry::instance()->return_url_for( 'plain-plug' ) );
		self::assertSame( '', Registry::instance()->return_url_for( 'unregistered' ) );
	}

	public function test_home_url_fallback_also_carries_gateway_marker(): void {
		$query = $this->checkout_success_query( null );

		self::assertSame( 'success', $query['wbcom_credits'], 'The fallback success URL must still mark the return.' );
		self::assertSame( Stripe::ID, $query['gateway'], 'Without the gateway param the return handler cannot know which gateway to claim against.' );
		self::assertSame( '100', $query['credits'] );
		self::assertArrayHasKey( 'session_id', $query, 'Stripe replaces the {CHECKOUT_SESSION_ID} placeholder appended to every branch.' );
	}
}
