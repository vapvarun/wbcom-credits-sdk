<?php
/**
 * Expiring credit lots.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * A top-up can carry an `expires_at` (a pack configured to expire, or a
 * consumer calling Credits::topup() with one). Hourly, each lapsed lot gets
 * one `expiry` row (item_id = the lot's row id, which is also how a lot is
 * known to be done) removing what is left of it.
 *
 * What is left assumes credits are spent oldest first: the newer, still
 * valid lots are counted as the part of the balance that survives, and the
 * lapsed lot can only take the rest, never more than it granted.
 *
 * @since 1.9.0
 */
final class Expiry {

	public const CRON_HOOK = 'wbcom_credits_expire_lots';

	/**
	 * Lots per pass per slug.
	 *
	 * @var int
	 */
	private const BATCH = 200;

	/**
	 * What is left of a lapsed lot.
	 *
	 * @since 1.9.0
	 * @param int $lot     Credits the lot granted.
	 * @param int $balance Current balance.
	 * @param int $newer   Credits from newer lots that are still valid.
	 * @return int
	 */
	public static function remaining( int $lot, int $balance, int $newer ): int {
		return max( 0, min( $lot, $balance - $newer ) );
	}

	/**
	 * Expire the lapsed lots of one slug.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return int Lots processed.
	 */
	public static function run( string $slug ): int {
		global $wpdb;
		$config = Registry::instance()->get( $slug );
		if ( ! is_array( $config ) ) {
			return 0;
		}
		$table = Ledger::table_name( (string) $config['prefix'] );
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the registry; values prepared.
		$lots = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.user_id, t.amount FROM {$table} t
				 WHERE t.entry_type = 'topup' AND t.expires_at IS NOT NULL AND t.expires_at <= %s
				   AND NOT EXISTS ( SELECT 1 FROM {$table} e WHERE e.entry_type = 'expiry' AND e.item_id = t.id )
				 ORDER BY t.id ASC LIMIT %d",
				$now,
				self::BATCH
			),
			ARRAY_A
		);

		foreach ( $lots as $lot ) {
			$user_id = (int) $lot['user_id'];
			Credits::with_user_lock(
				$slug,
				$user_id,
				static function () use ( $wpdb, $table, $slug, $config, $lot, $user_id, $now ): void {
					$newer     = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COALESCE( SUM( amount ), 0 ) FROM {$table}
							 WHERE user_id = %d AND entry_type = 'topup' AND id > %d AND ( expires_at IS NULL OR expires_at > %s )",
							$user_id,
							(int) $lot['id'],
							$now
						)
					);
					$remaining = self::remaining( (int) $lot['amount'], Credits::get_balance( $slug, $user_id ), $newer );

					// A zero row still marks the lot done.
					Ledger::insert( (string) $config['prefix'], $user_id, 'expiry', -$remaining, (int) $lot['id'], sprintf( 'Credits expired (lot #%d)', (int) $lot['id'] ), null, 'expiry', 'lot:' . (int) $lot['id'] );
					Credits::forget_balance( $slug, $user_id );

					if ( $remaining > 0 ) {
						/**
						 * Fires when credits from a lapsed lot are removed.
						 *
						 * @since 1.9.0
						 *
						 * @param string $slug      Plugin slug.
						 * @param int    $user_id   User ID.
						 * @param int    $remaining Credits removed.
						 * @param int    $lot_id    Top-up ledger row id.
						 */
						$expired_args = array( $slug, $user_id, $remaining, (int) $lot['id'] );
						Ledger::after_commit( static fn () => do_action( 'wbcom_credits_expired', ...$expired_args ) );
					}
				}
			);
		}
		// phpcs:enable

		return count( $lots );
	}

	/**
	 * Cron: every registered slug.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function run_all(): void {
		foreach ( Registry::instance()->get_slugs() as $slug ) {
			self::run( $slug );
		}
	}
}
