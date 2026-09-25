<?php
/**
 * Consumer hold state: settle an open hold once, never refund a settled one.
 *
 * Found on WB Listora (card 10336800031). The consumer settled or released on
 * every approve / reject event whatever had happened before, so deactivating a
 * listing whose submission cost was already settled refunded it: take the
 * paid listing down, get the credits back, put it up again for free.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! class_exists( 'WP_Post' ) ) {
		// Minimal WP_Post for the consumer's instanceof check.
		final class WP_Post { // phpcs:ignore
			/** @var int */
			public $ID = 0;
			/** @var int */
			public $post_author = 0;
		}
	}
}

namespace Wbcom\Credits {
	/** @var array<int, array<string, mixed>> Post meta seen by the shadowed helpers. */
	$GLOBALS['wbcom_credits_test_postmeta'] = array();

	function get_post( $id ) {
		$post              = new \WP_Post();
		$post->ID          = (int) $id;
		$post->post_author = \Wbcom\Credits\Tests\Credits\ConsumerStateTest::USER;
		return $post;
	}

	function get_post_meta( $id, $key, $single = false ) {
		return $GLOBALS['wbcom_credits_test_postmeta'][ (int) $id ][ $key ] ?? '';
	}

	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['wbcom_credits_test_postmeta'][ (int) $id ][ $key ] = $value;
		return true;
	}
}

namespace Wbcom\Credits\Tests\Credits {

	use PHPUnit\Framework\TestCase;
	use Wbcom\Credits\Consumer;
	use Wbcom\Credits\Credits;
	use Wbcom\Credits\Ledger;
	use Wbcom\Credits\Registry;
	use Wbcom\Credits\Tests\Support\FakeWpdb;

	final class ConsumerStateTest extends TestCase {

		private const SLUG = 'consumer-state';
		public const USER  = 88;
		private const ITEM = 4242;

		protected function setUp(): void {
			global $wpdb, $wbcom_credits_test_options, $wbcom_credits_test_filters;

			$wpdb                                   = new FakeWpdb();
			$wbcom_credits_test_options             = array();
			$wbcom_credits_test_filters             = array();
			$GLOBALS['wbcom_credits_test_postmeta'] = array();

			Registry::instance()->register(
				array(
					'slug'    => self::SLUG,
					'prefix'  => 'cst',
					'version' => '1.0.0',
					'money'   => array( 'currency' => 'USD' ),
				)
			);
			Ledger::maybe_create_table( 'cst' );
		}

		private function consumer(): Consumer {
			return new Consumer( self::SLUG, 'cst', array( 'id' => 'listing', 'label' => 'Listing', 'cost' => 10 ) );
		}

		public function test_settle_charges_the_hold_once(): void {
			Credits::topup_money( self::SLUG, self::USER, 30.0, '', 'seed' );
			$c = $this->consumer();

			$c->on_hold( self::ITEM );
			$this->assertSame( 20.0, Credits::balance_money( self::SLUG, self::USER ), 'Held.' );
			$c->on_hold( self::ITEM );
			$c->on_deduct( self::ITEM );
			$c->on_deduct( self::ITEM ); // A republish.

			$this->assertSame( 20.0, Credits::balance_money( self::SLUG, self::USER ) );
		}

		public function test_a_settled_item_is_not_refunded(): void {
			Credits::topup_money( self::SLUG, self::USER, 30.0, '', 'seed' );
			$c = $this->consumer();

			$c->on_hold( self::ITEM );
			$c->on_deduct( self::ITEM );
			$c->on_refund( self::ITEM ); // Deactivated after going live.

			$this->assertSame( 20.0, Credits::balance_money( self::SLUG, self::USER ) );
		}

		public function test_a_rejected_hold_is_released_once(): void {
			Credits::topup_money( self::SLUG, self::USER, 30.0, '', 'seed' );
			$c = $this->consumer();

			$c->on_hold( self::ITEM );
			$c->on_refund( self::ITEM );
			$c->on_refund( self::ITEM );

			$this->assertSame( 30.0, Credits::balance_money( self::SLUG, self::USER ) );
		}

		public function test_short_balance_holds_nothing(): void {
			Credits::topup_money( self::SLUG, self::USER, 5.0, '', 'seed' );
			$c = $this->consumer();

			$c->on_hold( self::ITEM );

			$this->assertSame( 5.0, Credits::balance_money( self::SLUG, self::USER ) );
		}
	}
}
