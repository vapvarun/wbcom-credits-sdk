<?php
/**
 * Schema contract — the SDK ships a single canonical Ledger schema.
 * Consumer plugins MUST NOT rename or override the columns.
 *
 * Why this test exists: wp-career-board-pro 1.1.0 (pre-SDK-1.3.0) shipped
 * its own CREATE TABLE with employer_id/post_id columns, pre-empting the
 * SDK's `maybe_create_table()`. All SDK queries then failed with
 * "Unknown column 'user_id'" and silently returned balance=0.
 *
 * This test locks the canonical column names so a future refactor that
 * accidentally renames them surfaces as a CI failure, not a production
 * silent-fail. It also exists as a single-source-of-truth reference for
 * consumer plugins onboarding to the SDK.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Ledger;

use PHPUnit\Framework\TestCase;

final class SchemaContractTest extends TestCase {

	/**
	 * The canonical Ledger columns. Adding a column is fine. Renaming an
	 * existing one is a breaking change that requires a major SDK bump.
	 *
	 * @return array<int, string>
	 */
	private function expected_columns(): array {
		return array( 'id', 'user_id', 'item_id', 'entry_type', 'amount', 'note', 'created_at' );
	}

	public function test_ledger_create_table_declares_canonical_columns(): void {
		$src = file_get_contents( __DIR__ . '/../../src/Ledger.php' );

		$this->assertNotFalse( $src, 'Ledger.php must be readable' );
		$this->assertMatchesRegularExpression(
			'/CREATE TABLE \{\$table\}/i',
			$src,
			'Ledger must own a CREATE TABLE definition'
		);

		foreach ( $this->expected_columns() as $col ) {
			$this->assertStringContainsString(
				$col,
				$src,
				"Ledger schema must declare the canonical column '{$col}'"
			);
		}
	}

	public function test_balance_query_uses_canonical_user_column(): void {
		$src = file_get_contents( __DIR__ . '/../../src/Ledger.php' );

		$this->assertStringContainsString(
			'WHERE user_id = %d',
			$src,
			'Balance query must reference the canonical user_id column'
		);
	}

	public function test_history_query_uses_canonical_user_column(): void {
		$src = file_get_contents( __DIR__ . '/../../src/Ledger.php' );

		$this->assertMatchesRegularExpression(
			'/SELECT[^"]+user_id[^"]+WHERE user_id = %d/i',
			$src,
			'History query must SELECT and WHERE on the canonical user_id column'
		);
	}

	public function test_insert_uses_canonical_keys(): void {
		$src = file_get_contents( __DIR__ . '/../../src/Ledger.php' );

		$this->assertStringContainsString(
			"'user_id'    => \$user_id",
			$src,
			'Insert must pass the canonical user_id key (not employer_id, attendee_id, member_id, etc.)'
		);
		$this->assertStringContainsString(
			"'item_id'    => \$item_id",
			$src,
			'Insert must pass the canonical item_id key (not post_id, booking_id, course_id, etc.)'
		);
	}

	public function test_no_per_consumer_column_template_substitution(): void {
		$src = file_get_contents( __DIR__ . '/../../src/Ledger.php' );

		$forbidden_patterns = array(
			'{user_column}',
			'{$user_column}',
			'{item_column}',
			'{$item_column}',
			'user_type_column',
		);

		foreach ( $forbidden_patterns as $pattern ) {
			$this->assertStringNotContainsString(
				$pattern,
				$src,
				"Ledger must NOT template column names — Option B locks a uniform schema. Found forbidden pattern: {$pattern}"
			);
		}
	}
}
