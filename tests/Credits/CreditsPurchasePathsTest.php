<?php
/**
 * Credits purchase-paths composite test.
 *
 * Locks the composite added for
 * github.com/vapvarun/wbcom-credits-sdk#7: the SDK owns the answer to "can a
 * member buy credits here?", so consumers stop assembling their own from
 * different subsets of the same primitives and disagreeing with each other.
 *
 * The regression that motivated it: a consumer's member-facing Credits UI was
 * gated on an answer that counted gateways but NOT adapter mappings, so a site
 * selling credits through a mapped WooCommerce product hid the UI from members
 * who could genuinely buy.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Credits;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Registry;

final class CreditsPurchasePathsTest extends TestCase {

	private const SLUG   = 'paths-plug';
	private const PREFIX = 'pathsp';

	protected function setUp(): void {
		global $wbcom_credits_test_options, $wbcom_credits_test_filters;

		$wbcom_credits_test_options = array();
		$wbcom_credits_test_filters = array();

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);
	}

	/**
	 * No gateway, no mapping, no URL: every route is false.
	 *
	 * This is the case the gate exists for. A consumer showing credit UI here
	 * sends members to an empty storefront, so `can_purchase()` MUST stay
	 * false - a fix that makes the composite permissive would be worse than
	 * the bug it replaced.
	 */
	public function test_bare_site_has_no_purchase_route(): void {
		$paths = Credits::purchase_paths( self::SLUG );

		$this->assertSame(
			array(
				'gateway'      => false,
				'mapping'      => false,
				'external_url' => false,
			),
			$paths
		);
		$this->assertFalse( Credits::can_purchase( self::SLUG ) );
	}

	/**
	 * A same-site purchase URL does NOT count on its own.
	 *
	 * It is normally the auto-set link to the consumer's own packs screen.
	 * Counting it would re-create the dead end: the member follows the link
	 * and arrives at a page that cannot take payment.
	 */
	public function test_same_site_purchase_url_is_not_a_route(): void {
		add_filter(
			'wbcom_credits_purchase_url',
			static fn(): string => 'https://example.test/buy-credits/'
		);

		$paths = Credits::purchase_paths( self::SLUG );

		$this->assertFalse( $paths['external_url'] );
		$this->assertFalse( Credits::can_purchase( self::SLUG ) );
	}

	/**
	 * An OFF-site purchase URL is a real route.
	 */
	public function test_offsite_purchase_url_is_a_route(): void {
		add_filter(
			'wbcom_credits_purchase_url',
			static fn(): string => 'https://shop.example.com/credits'
		);

		$paths = Credits::purchase_paths( self::SLUG );

		$this->assertTrue( $paths['external_url'] );
		$this->assertTrue( Credits::can_purchase( self::SLUG ) );
	}

	/**
	 * A mapping to an UNAVAILABLE adapter is not a route.
	 *
	 * Half the contract: a mapped WooCommerce product grants nothing when
	 * WooCommerce is not active. No adapter class is registered in this test,
	 * so nothing resolves and nothing may be counted.
	 */
	public function test_mapping_to_unavailable_adapter_is_not_a_route(): void {
		update_option(
			self::SLUG . '_credit_mappings',
			array(
				array(
					'adapter' => 'woocommerce',
					'item_id' => 130,
					'credits' => 25,
				),
			)
		);

		$paths = Credits::purchase_paths( self::SLUG );

		$this->assertFalse( $paths['mapping'] );
	}

	/**
	 * Consumers contribute their own routes through the filter, and a
	 * contributed route counts.
	 *
	 * This is how a consumer's own credit-pack products reach the composite
	 * without the SDK needing to know what a "pack" is, and without the
	 * consumer keeping a second whole answer.
	 */
	public function test_consumer_can_contribute_a_route(): void {
		add_filter(
			'wbcom_credits_purchase_paths',
			static function ( array $paths ): array {
				$paths['pack_url'] = true;
				return $paths;
			}
		);

		$paths = Credits::purchase_paths( self::SLUG );

		$this->assertArrayHasKey( 'pack_url', $paths );
		$this->assertTrue( $paths['pack_url'] );
		$this->assertTrue( Credits::can_purchase( self::SLUG ) );
	}

	/**
	 * The return is always boolean-valued, whatever a filter puts in.
	 *
	 * Consumers read these keys straight into `if` and into templates; a
	 * truthy string would work by accident and a `'0'` would not, so the
	 * shape is asserted rather than trusted.
	 */
	public function test_routes_are_cast_to_booleans(): void {
		add_filter(
			'wbcom_credits_purchase_paths',
			static function ( array $paths ): array {
				$paths['pack_url'] = '1';
				return $paths;
			}
		);

		$paths = Credits::purchase_paths( self::SLUG );

		$this->assertIsBool( $paths['pack_url'] );
		$this->assertTrue( $paths['pack_url'] );
	}
}
