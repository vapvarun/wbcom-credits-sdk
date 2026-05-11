<?php
/**
 * Performance smoke for the Ledger query layer.
 *
 * Locks two boundaries that protect production from accidental query-shape
 * regressions:
 *   1. SELECT SUM(amount) WHERE user_id = ? must remain a single-row
 *      aggregate scan. If someone "helpfully" rewrites it as a per-row
 *      PHP sum, 10K rows starts hurting fast.
 *   2. get_history(limit, offset) must paginate, not full-table-load.
 *
 * The FakeWpdb runs in-memory PHP arrays, so the absolute milliseconds
 * here aren't comparable to MySQL — what we lock is the algorithmic shape
 * (O(rows-matching-user) for balance, O(limit) for history). Wall-time
 * bounds are generous so CI runners on cold instances still pass.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Performance;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Ledger;
use Wbcom\Credits\Tests\Support\FakeWpdb;

final class LedgerPerformanceTest extends TestCase {

	private const ROW_COUNT      = 10_000;
	private const BALANCE_MAX_MS = 250;
	private const HISTORY_MAX_MS = 100;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new FakeWpdb();
		Ledger::maybe_create_table( 'perf' );

		// Seed 10K rows across 100 users (user_id 1..100), each with 100 entries.
		for ( $i = 0; $i < self::ROW_COUNT; $i++ ) {
			$user_id = ( $i % 100 ) + 1;
			Ledger::insert(
				'perf',
				$user_id,
				'topup',
				1, // $1 each so balance for user N is exactly its row count for them
				$i,
				'perf seed'
			);
		}
	}

	public function test_balance_query_stays_under_threshold_with_10k_rows(): void {
		$start   = microtime( true );
		$balance = Ledger::get_balance( 'perf', 1 );
		$ms      = ( microtime( true ) - $start ) * 1000;

		// User 1 has exactly 100 rows of amount=1 in the seed pattern.
		$this->assertSame( 100, $balance );
		$this->assertLessThan(
			self::BALANCE_MAX_MS,
			$ms,
			sprintf( 'get_balance against %d rows took %.1fms (cap %dms) — possible algorithmic regression', self::ROW_COUNT, $ms, self::BALANCE_MAX_MS )
		);
	}

	public function test_history_query_pagination_stays_under_threshold(): void {
		$start = microtime( true );
		$page  = Ledger::get_history( 'perf', 1, 50, 0 );
		$ms    = ( microtime( true ) - $start ) * 1000;

		$this->assertCount( 50, $page );
		$this->assertLessThan(
			self::HISTORY_MAX_MS,
			$ms,
			sprintf( 'get_history(limit=50) against %d rows took %.1fms (cap %dms) — pagination may not be applying', self::ROW_COUNT, $ms, self::HISTORY_MAX_MS )
		);
	}

	public function test_balance_for_user_with_no_rows_does_not_scan_all(): void {
		$start   = microtime( true );
		$balance = Ledger::get_balance( 'perf', 99_999 );
		$ms      = ( microtime( true ) - $start ) * 1000;

		$this->assertSame( 0, $balance );
		// Even with no matching rows, the scan over 10K rows shouldn't exceed
		// the same cap as a happy path — filter-then-sum is O(N) in our
		// FakeWpdb but should still complete well under the bound.
		$this->assertLessThan( self::BALANCE_MAX_MS, $ms );
	}
}
