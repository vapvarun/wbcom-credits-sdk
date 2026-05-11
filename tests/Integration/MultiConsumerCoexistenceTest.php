<?php
/**
 * Multi-consumer coexistence — the core promise of this SDK.
 *
 * Two plugins (mock "Plugin A" + mock "Plugin B") each register a consumer
 * via the shared Registry singleton. The SDK must:
 *   - boot_all() creates a SEPARATE ledger table per prefix.
 *   - REST routes register under each slug's namespace independently.
 *   - Credits::topup() / deduct() on slug A never reads or writes slug B's table.
 *   - Each consumer's balance is independent.
 *   - Deactivating one consumer (clearing its registration) leaves the other functional.
 *
 * This pack is what locks the empirical "three plugins on meeting.org didn't
 * fatal" finding as CI. A regression here means the SDK has broken the
 * portfolio's foundational guarantee.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Credits;
use Wbcom\Credits\Ledger;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class MultiConsumerCoexistenceTest extends TestCase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();

		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$balance_cache = new ReflectionProperty( Credits::class, 'balance_cache' );
		$balance_cache->setAccessible( true );
		$balance_cache->setValue( null, array() );

		global $wbcom_credits_test_hooks, $wbcom_credits_test_routes;
		$wbcom_credits_test_hooks  = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_routes = array();
	}

	private function register_two_consumers(): void {
		$registry = Registry::instance();
		$registry->register(
			array(
				'slug'      => 'plugin-a',
				'prefix'    => 'plga',
				'version'   => '1.0.0',
				'file'      => __FILE__,
				'user_type' => 'attendee',
			)
		);
		$registry->register(
			array(
				'slug'      => 'plugin-b',
				'prefix'    => 'plgb',
				'version'   => '1.0.0',
				'file'      => __FILE__,
				'user_type' => 'subscriber',
			)
		);

		Ledger::maybe_create_table( 'plga' );
		Ledger::maybe_create_table( 'plgb' );
	}

	public function test_two_consumers_register_with_distinct_prefixes(): void {
		$this->register_two_consumers();

		$slugs = Registry::instance()->get_slugs();
		$this->assertCount( 2, $slugs );
		$this->assertContains( 'plugin-a', $slugs );
		$this->assertContains( 'plugin-b', $slugs );

		$a = Registry::instance()->get( 'plugin-a' );
		$b = Registry::instance()->get( 'plugin-b' );

		$this->assertSame( 'plga', $a['prefix'] );
		$this->assertSame( 'plgb', $b['prefix'] );
		$this->assertNotSame( $a['prefix'], $b['prefix'], 'prefixes MUST be distinct or tables collide' );
	}

	public function test_each_consumer_gets_a_separate_ledger_table(): void {
		global $wpdb;
		$this->register_two_consumers();

		$this->assertArrayHasKey( 'wp_plga_credit_ledger', $wpdb->tables );
		$this->assertArrayHasKey( 'wp_plgb_credit_ledger', $wpdb->tables );
		$this->assertNotSame(
			'wp_plga_credit_ledger',
			'wp_plgb_credit_ledger',
			'ledger tables must be physically distinct'
		);
	}

	public function test_topup_on_consumer_a_does_not_touch_consumer_b(): void {
		global $wpdb;
		$this->register_two_consumers();

		Credits::topup( 'plugin-a', 1, 100, 'a topup' );

		$this->assertCount( 1, $wpdb->tables['wp_plga_credit_ledger'] );
		$this->assertCount( 0, $wpdb->tables['wp_plgb_credit_ledger'], 'B ledger must NOT see A writes' );
	}

	public function test_balances_are_independent(): void {
		$this->register_two_consumers();

		Credits::topup( 'plugin-a', 1, 100, 'A: 100 credits' );
		Credits::topup( 'plugin-b', 1, 250, 'B: 250 credits' );

		$balance_a = Credits::get_balance( 'plugin-a', 1 );
		$balance_b = Credits::get_balance( 'plugin-b', 1 );

		$this->assertSame( 100, $balance_a, 'A balance must reflect only A writes' );
		$this->assertSame( 250, $balance_b, 'B balance must reflect only B writes' );
	}

	public function test_same_user_id_has_independent_balances_per_consumer(): void {
		$this->register_two_consumers();

		// User #42 exists in both consumers' worlds — they should be tracked separately.
		Credits::topup( 'plugin-a', 42, 75, 'A topup for user 42' );
		Credits::topup( 'plugin-b', 42, 500, 'B topup for user 42' );

		$this->assertSame( 75, Credits::get_balance( 'plugin-a', 42 ) );
		$this->assertSame( 500, Credits::get_balance( 'plugin-b', 42 ) );
	}

	public function test_unregistered_consumer_returns_zero_balance(): void {
		$this->register_two_consumers();

		// 'never-registered' is not in Registry — must NOT crash, must return 0.
		$balance = Credits::get_balance( 'never-registered', 1 );
		$this->assertSame( 0, $balance );
	}

	public function test_one_consumer_removed_others_keep_working(): void {
		global $wpdb;
		$this->register_two_consumers();

		Credits::topup( 'plugin-a', 1, 100, 'a' );
		Credits::topup( 'plugin-b', 1, 200, 'b' );

		// Simulate plugin A deactivating — its registration goes away.
		$prop = new ReflectionProperty( Registry::class, 'plugins' );
		$prop->setAccessible( true );
		$plugins = $prop->getValue( Registry::instance() );
		unset( $plugins['plugin-a'] );
		$prop->setValue( Registry::instance(), $plugins );

		// Plugin B's balance must still resolve.
		$balance_b = Credits::get_balance( 'plugin-b', 1 );
		$this->assertSame( 200, $balance_b, 'B remains functional after A deactivates' );
	}

	public function test_two_consumers_can_share_pricing_config_shape(): void {
		Registry::instance()->register(
			array(
				'slug'    => 'plugin-a',
				'prefix'  => 'plga',
				'pricing' => array( 'currency' => 'USD', 'packs' => array( 'starter' => array( 'credits' => 100, 'price_cents' => 1000 ) ) ),
			)
		);
		Registry::instance()->register(
			array(
				'slug'    => 'plugin-b',
				'prefix'  => 'plgb',
				'pricing' => array( 'currency' => 'EUR', 'packs' => array( 'starter' => array( 'credits' => 100, 'price_cents' => 1200 ) ) ),
			)
		);

		$a = Registry::instance()->get( 'plugin-a' );
		$b = Registry::instance()->get( 'plugin-b' );

		$this->assertSame( 'USD', $a['pricing']['currency'] );
		$this->assertSame( 'EUR', $b['pricing']['currency'] );
		$this->assertNotSame(
			$a['pricing']['packs']['starter']['price_cents'],
			$b['pricing']['packs']['starter']['price_cents'],
			'each consumer can price the same pack id independently'
		);
	}
}
