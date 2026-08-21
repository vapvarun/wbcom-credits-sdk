<?php
/**
 * Append-only credit ledger — DB operations.
 *
 * @package Wbcom\Credits
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the credit_ledger table: insert rows, read balance, query history.
 *
 * Balance = SUM( amount ) across all rows for a user.
 * The only physical DELETE is cancel_hold().
 *
 * @since 1.0.0
 */
final class Ledger {

	/**
	 * Get the full table name for a plugin prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Plugin prefix, e.g. 'wcb'.
	 * @return string Full table name with wpdb prefix.
	 */
	public static function table_name( string $prefix ): string {
		global $wpdb;
		return $wpdb->prefix . $prefix . '_credit_ledger';
	}

	/**
	 * Create the ledger table if it doesn't exist.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Plugin prefix.
	 * @return void
	 */
	public static function maybe_create_table( string $prefix ): void {
		$table = self::table_name( $prefix );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			entry_type VARCHAR(20) NOT NULL,
			amount INT NOT NULL,
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			INDEX idx_user_id (user_id),
			INDEX idx_entry_type (entry_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get the current credit balance for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @return int Balance (may be negative).
	 */
	public static function get_balance( string $prefix, int $user_id ): int {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( amount ), 0 ) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);

		return (int) $sum;
	}

	/**
	 * Get recent ledger entries for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $limit   Max rows to return.
	 * @param int    $offset  Pagination offset.
	 * @return array Array of ledger row objects.
	 */
	public static function get_history( string $prefix, int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, item_id, entry_type, amount, note, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$limit,
				$offset
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert a ledger row (append-only).
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix     Plugin prefix.
	 * @param int    $user_id    WordPress user ID.
	 * @param string $entry_type One of: topup, hold, deduction, refund.
	 * @param int    $amount     Signed integer (negative for debits).
	 * @param int    $item_id    Associated item ID (0 if not applicable).
	 * @param string $note       Human-readable note.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function insert( string $prefix, int $user_id, string $entry_type, int $amount, int $item_id = 0, string $note = '' ): int|false {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'item_id'    => $item_id,
				'entry_type' => $entry_type,
				'amount'     => $amount,
				'note'       => $note,
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		return false === $result ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Deduct with transaction safety: release hold + permanent deduction.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $cost    Credit cost (positive).
	 * @param int    $item_id Associated item ID.
	 * @param string $note    Deduction note.
	 * @return bool True on success.
	 */
	public static function deduct_with_hold_release( string $prefix, int $user_id, int $cost, int $item_id, string $note = '' ): bool {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Release the hold.
		$refund = self::insert( $prefix, $user_id, 'refund', $cost, $item_id, 'Hold released on approval' );
		if ( false === $refund ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return false;
		}

		// Permanent deduction.
		$deduction_note = $note ?: 'Credits deducted';
		$deduct         = self::insert( $prefix, $user_id, 'deduction', -$cost, $item_id, $deduction_note );
		if ( false === $deduct ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return false;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return true;
	}

	/**
	 * Cancel (physically delete) an unconsumed hold row.
	 *
	 * Only hard DELETE in the append-only ledger. Use when an item is
	 * trashed before approval/rejection so no refund entry is needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $item_id Associated item ID.
	 * @return void
	 */
	public static function cancel_hold( string $prefix, int $user_id, int $item_id ): void {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array(
				'item_id'    => $item_id,
				'user_id'    => $user_id,
				'entry_type' => 'hold',
			),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * Cancel (physically delete) a SPECIFIC unconsumed hold row by its ledger id.
	 *
	 * Unlike cancel_hold() — which deletes EVERY 'hold' row for (user_id,
	 * item_id) — this targets one row by primary key. Required whenever more
	 * than one hold can share an item_id over time: deduct_with_hold_release()
	 * leaves the committed attempt's 'hold' row physically in place (its balance
	 * effect is reversed by a paired 'refund' release entry, not by deletion), so
	 * a broad cancel_hold(item_id) on a later failed attempt would also delete a
	 * PRIOR committed attempt's lingering hold row — un-balancing that charge
	 * (the release entry survives, silently reversing the deduction). Deleting by
	 * the exact hold id returned by Credits::hold()/hold_money() only ever removes
	 * the still-unconsumed reservation the caller placed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $hold_id Ledger row id returned by hold()/hold_money().
	 * @return void
	 */
	public static function cancel_hold_by_id( string $prefix, int $user_id, int $hold_id ): void {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array(
				'id'         => $hold_id,
				'user_id'    => $user_id,
				'entry_type' => 'hold',
			),
			array( '%d', '%d', '%s' )
		);
	}
}
