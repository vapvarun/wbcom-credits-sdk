<?php
/**
 * Transaction_Log v1 → v2 schema upgrade path.
 *
 * SDK 1.2.0 shipped the gateway-log table WITHOUT a `payment_intent` column
 * (and without the idx_intent index). SDK 1.3.x added both, but only in the
 * fresh CREATE TABLE branch — `maybe_create_table()` early-returned when the
 * table already existed, so EXISTING installs never got the column. The Stripe
 * refund-linkage fix (HIGH-6) resolves a refund's parent checkout via that
 * column, so on upgraded sites refunds silently failed to link.
 *
 * This test reproduces a real v1 install (a pre-existing table missing the
 * column), runs the upgrade, and locks that the column + index now exist and
 * the payment_intent refund lookup works — i.e. the migration lands on
 * existing data, not just fresh sites. It also asserts the upgrade is
 * re-runnable (idempotent), so a double-boot can't error or double-add.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Registry;
use Wbcom\Credits\Gateways\Stripe;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class TransactionLogUpgradeTest extends TestCase {

	private const SLUG   = 'legacy-plug';
	private const PREFIX = 'lgcy';

	private FakeWpdb $db;

	protected function setUp(): void {
		global $wpdb;
		$this->db = new FakeWpdb();
		$wpdb     = $this->db;

		// Reset the Registry singleton + hooks between tests.
		$prop = new ReflectionProperty( Registry::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		global $wbcom_credits_test_hooks, $wbcom_credits_test_options;
		$wbcom_credits_test_hooks   = array( 'actions' => array(), 'filters' => array() );
		$wbcom_credits_test_options = array();

		Registry::instance()->register(
			array(
				'slug'    => self::SLUG,
				'prefix'  => self::PREFIX,
				'version' => '1.0.0',
			)
		);
	}

	/**
	 * Seed a pre-existing SDK-1.2.0 gateway-log table: same name as v2 but
	 * WITHOUT payment_intent / idx_intent (exactly what shipped before the
	 * column was added).
	 */
	private function seed_v1_table(): string {
		$table = Transaction_Log::table_name( self::PREFIX );
		$this->db->record_create_table(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				slug VARCHAR(64) NOT NULL,
				gateway VARCHAR(32) NOT NULL,
				kind VARCHAR(16) NOT NULL,
				session_id VARCHAR(191) NOT NULL,
				event_id VARCHAR(191) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				credits BIGINT NOT NULL DEFAULT 0,
				amount_cents BIGINT NOT NULL DEFAULT 0,
				refunded_cents BIGINT NOT NULL DEFAULT 0,
				currency VARCHAR(8) NOT NULL DEFAULT 'USD',
				ledger_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_session (slug, gateway, session_id),
				KEY idx_event (slug, gateway, event_id),
				KEY idx_user (slug, user_id)
			) DEFAULT CHARSET=utf8mb4;"
		);
		return $table;
	}

	public function test_v1_table_starts_without_payment_intent(): void {
		$table = $this->seed_v1_table();

		// Guard the premise: the shim must model a v1 table as truly missing
		// the column, otherwise this whole test proves nothing.
		self::assertNull(
			$this->db->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'payment_intent'" ),
			'Pre-upgrade v1 table must NOT have the payment_intent column.'
		);
		self::assertNull(
			$this->db->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_intent'" ),
			'Pre-upgrade v1 table must NOT have the idx_intent index.'
		);
	}

	public function test_upgrade_adds_payment_intent_column_and_index_to_existing_table(): void {
		$table = $this->seed_v1_table();

		// The real upgrade entry point (what the version gate calls).
		Transaction_Log::maybe_create_table( self::PREFIX );

		self::assertSame(
			'payment_intent',
			$this->db->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'payment_intent'" ),
			'v1 → v2 upgrade MUST add the payment_intent column to the existing table.'
		);
		self::assertSame(
			$table,
			$this->db->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_intent'" ),
			'v1 → v2 upgrade MUST add the idx_intent index to the existing table.'
		);
	}

	public function test_refund_linkage_lookup_works_after_upgrade(): void {
		$this->seed_v1_table();

		Transaction_Log::maybe_create_table( self::PREFIX );

		// After the column exists, a checkout can store payment_intent and the
		// Stripe refund secondary lookup can resolve the parent by it.
		Transaction_Log::insert_checkout(
			array(
				'slug'           => self::SLUG,
				'gateway'        => Stripe::ID,
				'session_id'     => 'cs_up',
				'payment_intent' => 'pi_up',
				'event_id'       => 'evt_up',
				'user_id'        => 7,
				'credits'        => 30,
				'amount_cents'   => 300,
				'currency'       => 'USD',
				'ledger_id'      => 1,
			)
		);

		$row = Transaction_Log::find_checkout_by_payment_intent( self::SLUG, Stripe::ID, 'pi_up' );

		self::assertIsArray( $row, 'Parent checkout must be resolvable by payment_intent after the upgrade.' );
		self::assertSame( 'cs_up', (string) $row['session_id'] );
		self::assertSame( 'pi_up', (string) $row['payment_intent'] );
	}

	public function test_upgrade_is_idempotent_on_rerun(): void {
		$table = $this->seed_v1_table();

		Transaction_Log::maybe_create_table( self::PREFIX );
		// Second boot must not error and must not double-declare the column/index.
		Transaction_Log::maybe_create_table( self::PREFIX );

		$columns = array_filter(
			$this->db->table_columns[ $table ] ?? array(),
			static fn ( $c ) => 'payment_intent' === $c
		);
		$indexes = array_filter(
			$this->db->table_indexes[ $table ] ?? array(),
			static fn ( $i ) => 'idx_intent' === $i
		);

		self::assertCount( 1, $columns, 'payment_intent must be declared exactly once after re-run.' );
		self::assertCount( 1, $indexes, 'idx_intent must be declared exactly once after re-run.' );
	}

	public function test_fresh_install_already_has_payment_intent(): void {
		// No seed — table does not yet exist. maybe_create_table runs the full
		// CREATE TABLE branch, which already declares the column + index, and
		// the backfill probe must then short-circuit (no harm, no double-add).
		$table = Transaction_Log::table_name( self::PREFIX );

		Transaction_Log::maybe_create_table( self::PREFIX );

		self::assertSame(
			'payment_intent',
			$this->db->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'payment_intent'" )
		);
		self::assertSame(
			$table,
			$this->db->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_intent'" )
		);
	}
}
