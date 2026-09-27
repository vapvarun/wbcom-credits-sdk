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
 * The only physical DELETE is cancelling a hold that is still open.
 *
 * Since 1.9.0 every row carries a `reason` (what happened: purchase, hold,
 * hold_release, spend, refund, gateway_refund, admin_adjust, topup), a
 * `reference` (the order / session / event it came from) and, for the rows
 * that close a hold, the `hold_id` of that hold. A hold is open while no row
 * points at it. Rows written before 1.9.0 have an empty reason and no
 * hold_id; {@see open_holds()} reads them by the net amount still held on
 * the item, which is how they were written.
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
		if ( ! $exists ) {
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				entry_type VARCHAR(20) NOT NULL,
				amount INT NOT NULL,
				note VARCHAR(255) NOT NULL DEFAULT '',
				expires_at DATETIME NULL DEFAULT NULL,
				reason VARCHAR(32) NOT NULL DEFAULT '',
				reference VARCHAR(191) NOT NULL DEFAULT '',
				hold_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				INDEX idx_user_id (user_id),
				INDEX idx_entry_type (entry_type),
				INDEX idx_item_id (item_id),
				INDEX idx_user_item_type (user_id, item_id, entry_type),
				INDEX idx_expiry (entry_type, expires_at),
				INDEX idx_user_created (user_id, created_at),
				INDEX idx_hold (hold_id),
				INDEX idx_reason (reason, created_at)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}

		self::maybe_upgrade( $prefix );
	}

	/**
	 * Bring a ledger created before 1.9.0 up to date.
	 *
	 * maybe_create_table() used to return as soon as the table existed, so
	 * no column or index added later ever reached an upgraded site. Adds
	 * each missing piece explicitly (dbDelta is unreliable at adding plain
	 * keys); idempotent.
	 *
	 * @since 1.9.0
	 *
	 * @param string $prefix Plugin prefix.
	 * @return void
	 */
	public static function maybe_upgrade( string $prefix ): void {
		$table = self::table_name( $prefix );

		self::ensure_column( $table, 'expires_at', 'DATETIME NULL DEFAULT NULL' );
		self::ensure_column( $table, 'reason', "VARCHAR(32) NOT NULL DEFAULT ''" );
		self::ensure_column( $table, 'reference', "VARCHAR(191) NOT NULL DEFAULT ''" );
		self::ensure_column( $table, 'hold_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
		self::ensure_key( $table, 'idx_item_id', '(item_id)' );
		self::ensure_key( $table, 'idx_user_item_type', '(user_id, item_id, entry_type)' );
		self::ensure_key( $table, 'idx_expiry', '(entry_type, expires_at)' );
		self::ensure_key( $table, 'idx_user_created', '(user_id, created_at)' );
		self::ensure_key( $table, 'idx_hold', '(hold_id)' );
		self::ensure_key( $table, 'idx_reason', '(reason, created_at)' );
	}

	/**
	 * Add a column to an existing table when it is missing.
	 *
	 * @since 1.9.0
	 *
	 * @param string $table      Full table name.
	 * @param string $column     Column name.
	 * @param string $definition Column definition.
	 * @return void
	 */
	private static function ensure_column( string $table, string $column, string $definition ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Add an index to an existing table when it is missing.
	 *
	 * @since 1.9.0
	 *
	 * @param string $table   Full table name.
	 * @param string $key     Index name.
	 * @param string $columns Column list, with parentheses.
	 * @return void
	 */
	private static function ensure_key( string $table, string $key, string $columns ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $key ) ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$key} {$columns}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
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

		// Inside the user's lock the read is a locking read: it waits for a
		// charge another request wrote inside its own, not yet committed,
		// transaction. A plain read would not see that row, and the named
		// lock is already released when that request's transaction commits.
		$lock = self::in_user_lock( $prefix, $user_id ) ? ' FOR UPDATE' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( amount ), 0 ) FROM {$table} WHERE user_id = %d{$lock}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				"SELECT id, user_id, item_id, entry_type, amount, note, reason, reference, hold_id, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$limit,
				$offset
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count a user's ledger rows, for paging get_history().
	 *
	 * @since 1.7.2
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @return int
	 */
	public static function count_for_user( string $prefix, int $user_id ): int {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @param string      $note       Human-readable note.
	 * @param string|null $expires_at UTC 'Y-m-d H:i:s' when a top-up's credits lapse (since 1.9.0).
	 * @param string $reason     What happened (since 1.9.0): purchase, topup, hold,
	 *                           hold_release, spend, refund, gateway_refund, admin_adjust.
	 * @param string $reference  Order / session / event the row came from (since 1.9.0).
	 * @param int    $hold_id    The hold this row closes, for hold_release and
	 *                           spend rows (since 1.9.0).
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function insert( string $prefix, int $user_id, string $entry_type, int $amount, int $item_id = 0, string $note = '', ?string $expires_at = null, string $reason = '', string $reference = '', int $hold_id = 0 ): int|false {
		global $wpdb;
		$table = self::table_name( $prefix );

		$row    = array(
			'user_id'    => $user_id,
			'item_id'    => $item_id,
			'entry_type' => $entry_type,
			'amount'     => $amount,
			'note'       => $note,
			'reason'     => sanitize_key( $reason ),
			'reference'  => substr( $reference, 0, 191 ),
			'hold_id'    => $hold_id,
			// UTC from PHP, never the column default: MySQL's
			// CURRENT_TIMESTAMP follows the server's time zone.
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$format = array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' );
		if ( null !== $expires_at ) {
			$row['expires_at'] = $expires_at;
			$format[]          = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table, $row, $format );

		return false === $result ? false : (int) $wpdb->insert_id;
	}


	// -------------------------------------------------------------------------
	// Holds (1.9.0: settled and released by id, never by item alone)
	// -------------------------------------------------------------------------

	/**
	 * The holds on an item that are still open, newest first.
	 *
	 * A 1.9.0 hold (reason `hold`) is open while no row carries its id in
	 * `hold_id`. Holds written earlier have no reason and were closed by
	 * unlinked `refund` rows (a release, or the release half of a
	 * deduction), so for those the amount still held is the legacy holds
	 * minus the legacy refunds on the item; the newest legacy holds up to
	 * that amount are open. Reads one item's rows (a handful) and decides
	 * in PHP, so it works the same on every MySQL / MariaDB version.
	 *
	 * @since 1.9.0
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $item_id Item ID.
	 * @return array<int, object> Open hold rows (id, amount negative), newest first.
	 */
	public static function open_holds( string $prefix, int $user_id, int $item_id ): array {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, entry_type, amount, reason, hold_id FROM {$table} WHERE user_id = %d AND item_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$item_id
			)
		);
		$rows = is_array( $rows ) ? $rows : array();

		$closed = array();
		foreach ( $rows as $row ) {
			if ( (int) $row->hold_id > 0 ) {
				$closed[ (int) $row->hold_id ] = true;
			}
		}

		$open           = array();
		$legacy_holds   = array();
		$legacy_release = 0;
		foreach ( $rows as $row ) {
			$id = (int) $row->id;
			if ( 'hold' === $row->entry_type && ! isset( $closed[ $id ] ) ) {
				if ( 'hold' === $row->reason ) {
					$open[] = $row;
				} elseif ( '' === $row->reason ) {
					$legacy_holds[] = $row;
				}
			} elseif ( 'refund' === $row->entry_type && '' === $row->reason && 0 === (int) $row->hold_id ) {
				$legacy_release += (int) $row->amount;
			}
		}

		// Legacy releases closed the oldest legacy holds first.
		foreach ( $legacy_holds as $row ) {
			$held = abs( (int) $row->amount );
			if ( $legacy_release >= $held ) {
				$legacy_release -= $held;
				continue;
			}
			$legacy_release = 0;
			$open[]         = $row;
		}

		usort( $open, static fn ( $a, $b ) => (int) $b->id <=> (int) $a->id );

		return $open;
	}

	/**
	 * One open hold by id, or null when it is not a hold, not this user's,
	 * or already settled, released or cancelled.
	 *
	 * @since 1.9.0
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $hold_id Ledger row id of the hold.
	 * @return object|null Row with id, item_id, amount (negative).
	 */
	public static function find_open_hold( string $prefix, int $user_id, int $hold_id ): ?object {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, item_id, entry_type, amount FROM {$table} WHERE id = %d AND user_id = %d AND entry_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hold_id,
				$user_id,
				'hold'
			)
		);
		if ( ! is_object( $row ) ) {
			return null;
		}

		foreach ( self::open_holds( $prefix, $user_id, (int) $row->item_id ) as $open ) {
			if ( (int) $open->id === $hold_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Settle an open hold: release it and write the permanent spend, both
	 * pointing at the hold, in one transaction under the user's lock.
	 *
	 * @since 1.9.0
	 *
	 * @param string $prefix    Plugin prefix.
	 * @param int    $user_id   WordPress user ID.
	 * @param int    $hold_id   Ledger row id of the hold.
	 * @param int    $cost      Amount to spend, in ledger units; 0 spends what was held.
	 *                          More than was held is refused (1.9.2).
	 * @param string $note      Spend note.
	 * @param string $reference Optional reference.
	 * @return int|false The spend row id, or false when the hold is not open or $cost is more than was held.
	 */
	public static function settle( string $prefix, int $user_id, int $hold_id, int $cost = 0, string $note = '', string $reference = '' ): int|false {
		return self::with_user_lock(
			$prefix,
			$user_id,
			static function () use ( $prefix, $user_id, $hold_id, $cost, $note, $reference ) {
				$hold = self::find_open_hold( $prefix, $user_id, $hold_id );
				if ( null === $hold ) {
					return false;
				}

				$held = abs( (int) $hold->amount );
				$cost = $cost > 0 ? $cost : $held;

				// Never charge more than was reserved: the extra would skip
				// the balance check the hold stood for (1.9.2). A price rise
				// holds the difference first (Consumer::reprice_item()).
				if ( $cost > $held ) {
					return false;
				}

				self::begin();
				$release = self::insert( $prefix, $user_id, 'refund', $held, (int) $hold->item_id, 'Hold released on approval', null, 'hold_release', $reference, $hold_id );
				$spend   = false === $release ? false : self::insert( $prefix, $user_id, 'deduction', -$cost, (int) $hold->item_id, $note ?: 'Credits deducted', null, 'spend', $reference, $hold_id );
				if ( false === $spend ) {
					self::rollback();
					return false;
				}
				self::commit();

				return $spend;
			}
		);
	}

	/**
	 * Release an open hold back to the balance.
	 *
	 * @since 1.9.0
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $hold_id Ledger row id of the hold.
	 * @param string $note    Release note.
	 * @return array{id: int, amount: int, item_id: int}|false The release row, or false when the hold is not open.
	 */
	public static function release( string $prefix, int $user_id, int $hold_id, string $note = '' ): array|false {
		return self::with_user_lock(
			$prefix,
			$user_id,
			static function () use ( $prefix, $user_id, $hold_id, $note ) {
				$hold = self::find_open_hold( $prefix, $user_id, $hold_id );
				if ( null === $hold ) {
					return false;
				}

				$held = abs( (int) $hold->amount );
				$id   = self::insert( $prefix, $user_id, 'refund', $held, (int) $hold->item_id, $note ?: 'Credits refunded', null, 'hold_release', '', $hold_id );

				return false === $id ? false : array( 'id' => $id, 'amount' => $held, 'item_id' => (int) $hold->item_id );
			}
		);
	}

	/**
	 * Settle the open hold on an item (the pre-1.9.0 call shape).
	 *
	 * Used to write a release and a deduction whether or not a hold was
	 * open, so with no open hold the two rows cancelled out and the member
	 * paid nothing. Now it settles the newest open hold on the item, or
	 * returns false.
	 *
	 * @since 1.0.0
	 * @since 1.9.0 Settles an open hold only; false when there is none.
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $cost    Credit cost (positive, ledger units).
	 * @param int    $item_id Associated item ID.
	 * @param string $note    Deduction note.
	 * @return bool True when a hold was settled.
	 */
	public static function deduct_with_hold_release( string $prefix, int $user_id, int $cost, int $item_id, string $note = '' ): bool {
		$open = self::open_holds( $prefix, $user_id, $item_id );
		if ( ! $open ) {
			return false;
		}

		return false !== self::settle( $prefix, $user_id, (int) $open[0]->id, $cost, $note );
	}

	/**
	 * Cancel (physically delete) the open holds on an item.
	 *
	 * Before 1.9.0 this deleted every hold row on the item, including one
	 * already settled, which silently reversed that charge. Settled and
	 * released holds are now never touched.
	 *
	 * @since 1.0.0
	 * @since 1.9.0 Open holds only.
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $item_id Associated item ID.
	 * @return void
	 */
	public static function cancel_hold( string $prefix, int $user_id, int $item_id ): void {
		foreach ( self::open_holds( $prefix, $user_id, $item_id ) as $open ) {
			self::delete_hold_row( $prefix, $user_id, (int) $open->id );
		}
	}

	/**
	 * Cancel (physically delete) one hold, only while it is open.
	 *
	 * @since 1.0.0
	 * @since 1.9.0 A settled or released hold is left alone.
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $hold_id Ledger row id returned by hold()/hold_money().
	 * @return void
	 */
	public static function cancel_hold_by_id( string $prefix, int $user_id, int $hold_id ): void {
		if ( null !== self::find_open_hold( $prefix, $user_id, $hold_id ) ) {
			self::delete_hold_row( $prefix, $user_id, $hold_id );
		}
	}

	/**
	 * Delete one hold row.
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $hold_id Row id.
	 * @return void
	 */
	private static function delete_hold_row( string $prefix, int $user_id, int $hold_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			self::table_name( $prefix ),
			array(
				'id'         => $hold_id,
				'user_id'    => $user_id,
				'entry_type' => 'hold',
			),
			array( '%d', '%d', '%s' )
		);
	}

	// -------------------------------------------------------------------------
	// Locks and transactions
	// -------------------------------------------------------------------------

	/**
	 * Transaction depth, so SDK calls nested inside SDK calls share one
	 * transaction. MySQL has no nested transactions: a second START
	 * TRANSACTION commits the first.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Begin a transaction, or join the one already open.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function begin(): void {
		global $wpdb;
		if ( 0 === self::$depth ) {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		++self::$depth;
	}

	/**
	 * Commit when the outermost caller finishes.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function commit(): void {
		global $wpdb;
		self::$depth = max( 0, self::$depth - 1 );
		if ( 0 === self::$depth ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::release_locks_at_end();
			self::run_after_commit();
		}
	}

	/**
	 * Run $fn once the outermost SDK transaction commits, or now when none
	 * is open. Dropped if the transaction rolls back.
	 *
	 * The SDK fires its actions through this (1.9.2): a listener used to run
	 * while the claim and the credit were still uncommitted, so it could see
	 * a purchase that was then rolled back, or break the transaction by
	 * opening its own.
	 *
	 * @since 1.9.2
	 * @param callable $fn Work to run after commit.
	 * @return void
	 */
	public static function after_commit( callable $fn ): void {
		if ( 0 === self::$depth ) {
			$fn();
			return;
		}
		self::$deferred[] = $fn;
	}

	/**
	 * Callbacks waiting for the outermost commit.
	 *
	 * @var array<int, callable>
	 */
	private static array $deferred = array();

	/**
	 * Run the deferred callbacks, in order, each once.
	 *
	 * @return void
	 */
	private static function run_after_commit(): void {
		while ( self::$deferred ) {
			$fn = array_shift( self::$deferred );
			$fn();
		}
	}

	/**
	 * Roll the whole transaction back, however deep the caller is.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function rollback(): void {
		global $wpdb;
		if ( self::$depth > 0 ) {
			self::$depth    = 0;
			self::$deferred = array();
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::release_locks_at_end();
		}
	}

	/**
	 * Run a callback while holding the user's ledger lock.
	 *
	 * A MySQL named lock per (table, user): every balance check and the
	 * write that follows it happen inside, so two requests cannot both
	 * pass the check. Re-entrant within a request. When the lock cannot be
	 * had within the timeout the callback does not run and false is
	 * returned: failing a charge is safe, overdrawing is not.
	 *
	 * @since 1.9.0
	 *
	 * @param string   $prefix  Plugin prefix.
	 * @param int      $user_id WordPress user ID.
	 * @param callable $fn      Work to do; its return value is passed back.
	 * @return mixed The callback's result, or false when the lock timed out.
	 */
	public static function with_user_lock( string $prefix, int $user_id, callable $fn ): mixed {
		return self::with_named_lock( self::lock_name( $prefix, $user_id ), $fn );
	}

	/**
	 * Run a callback while holding a MySQL named lock: the same re-entrant,
	 * fail-safe lock as with_user_lock(), for anything else that must be
	 * checked and written by one request at a time (a coupon's last use).
	 *
	 * @since 1.9.2
	 *
	 * @param string   $key Lock key; hashed, so any length.
	 * @param callable $fn  Work to do; its return value is passed back.
	 * @return mixed The callback's result, or false when the lock timed out.
	 */
	public static function with_lock( string $key, callable $fn ): mixed {
		return self::with_named_lock( 'wbcc_' . substr( md5( self::table_name( '' ) . '|' . $key ), 0, 24 ), $fn );
	}

	/**
	 * Hold a named lock around a callback.
	 *
	 * @param string   $name Lock name, at most 64 characters.
	 * @param callable $fn   Work.
	 * @return mixed
	 */
	private static function with_named_lock( string $name, callable $fn ): mixed {
		global $wpdb;

		if ( isset( self::$held[ $name ] ) ) {
			return $fn();
		}

		/**
		 * Seconds to wait for a user's ledger lock.
		 *
		 * @since 1.9.0
		 *
		 * @param int $timeout Seconds. Default 10.
		 */
		$timeout = (int) apply_filters( 'wbcom_credits_lock_timeout', 10 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, $timeout ) ) ) {
			return false;
		}

		self::$held[ $name ] = true;
		try {
			return $fn();
		} finally {
			// Inside an SDK transaction the lock is kept until it commits or
			// rolls back (1.9.2). Released earlier, the next request got the
			// lock while this one's writes were still uncommitted, and a plain
			// read (a refunded amount, a coupon's uses) could not see them.
			if ( self::$depth > 0 ) {
				self::$release_at_end[ $name ] = true;
			} else {
				self::release_named_lock( $name );
			}
		}
	}

	/**
	 * Locks to release when the open transaction ends.
	 *
	 * @var array<string, bool>
	 */
	private static array $release_at_end = array();

	/**
	 * Release one named lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	private static function release_named_lock( string $name ): void {
		global $wpdb;
		unset( self::$held[ $name ] );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Release every lock kept for the transaction that just ended.
	 *
	 * @return void
	 */
	private static function release_locks_at_end(): void {
		foreach ( array_keys( self::$release_at_end ) as $name ) {
			self::release_named_lock( $name );
		}
		self::$release_at_end = array();
	}

	/**
	 * Named locks this request holds.
	 *
	 * @var array<string, bool>
	 */
	private static array $held = array();

	/**
	 * Whether this request holds the user's ledger lock.
	 *
	 * @since 1.9.1
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @return bool
	 */
	public static function in_user_lock( string $prefix, int $user_id ): bool {
		return isset( self::$held[ self::lock_name( $prefix, $user_id ) ] );
	}

	/**
	 * The MySQL named lock for a user's ledger: per table (so per site),
	 * at most 64 characters.
	 *
	 * @param string $prefix  Plugin prefix.
	 * @param int    $user_id WordPress user ID.
	 * @return string
	 */
	private static function lock_name( string $prefix, int $user_id ): string {
		return 'wbcc_' . substr( md5( self::table_name( $prefix ) ), 0, 16 ) . '_' . $user_id;
	}

	// -------------------------------------------------------------------------
	// Reporting

	/**
	 * One ledger row by id, or null.
	 *
	 * @since 1.9.2
	 * @param string $prefix Plugin prefix.
	 * @param int    $id     Row id.
	 * @return object|null
	 */
	public static function get_row( string $prefix, int $id ): ?object {
		global $wpdb;
		$table = self::table_name( $prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, item_id, entry_type, amount, note, reason, reference, hold_id, expires_at, created_at FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_object( $row ) ? $row : null;
	}
	// -------------------------------------------------------------------------

	/**
	 * Rows matching a filter, or their count or amount total.
	 *
	 * Filters (all optional): user_id, user_ids (list), item_id, entry_type,
	 * reason (string or list), reference, hold_id, since / until (UTC
	 * 'Y-m-d H:i:s', since inclusive, until exclusive). Paging: limit
	 * (default 50, max 500), offset, order ('DESC' default, or 'ASC').
	 * Mode 'grouped' (1.9.2) returns key => {total, count} per group_by
	 * (reason, user_id, entry_type or item_id).
	 *
	 * @since 1.9.0
	 *
	 * @param string              $prefix Plugin prefix.
	 * @param array<string,mixed> $args   Filters and paging.
	 * @param string              $mode   'rows', 'count', 'sum' or 'grouped'.
	 * @return array<int|string, mixed>|int
	 */
	public static function query( string $prefix, array $args = array(), string $mode = 'rows' ): array|int {
		global $wpdb;
		$table = self::table_name( $prefix );

		// "1=1" keeps every filter an "AND ..." clause.
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'user_id', 'item_id', 'hold_id' ) as $col ) {
			if ( isset( $args[ $col ] ) ) {
				$where[]  = "{$col} = %d";
				$params[] = (int) $args[ $col ];
			}
		}
		if ( ! empty( $args['user_ids'] ) ) {
			$ids      = array_values( array_filter( array_map( 'absint', (array) $args['user_ids'] ) ) );
			$ids      = $ids ? $ids : array( 0 );
			$where[]  = 'user_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params   = array_merge( $params, $ids );
		}
		foreach ( array( 'entry_type', 'reference' ) as $col ) {
			if ( isset( $args[ $col ] ) && '' !== (string) $args[ $col ] ) {
				$where[]  = "{$col} = %s";
				$params[] = (string) $args[ $col ];
			}
		}
		if ( ! empty( $args['reason'] ) ) {
			$reasons  = array_values( array_map( 'sanitize_key', (array) $args['reason'] ) );
			$where[]  = 'reason IN (' . implode( ', ', array_fill( 0, count( $reasons ), '%s' ) ) . ')';
			$params   = array_merge( $params, $reasons );
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['since'];
		}
		if ( ! empty( $args['until'] ) ) {
			$where[]  = 'created_at < %s';
			$params[] = (string) $args['until'];
		}

		$where_sql = implode( ' AND ', $where );

		if ( 'grouped' === $mode ) {
			$group = in_array( $args['group_by'] ?? 'reason', array( 'reason', 'user_id', 'entry_type', 'item_id' ), true ) ? $args['group_by'] : 'reason';
			$sql   = "SELECT {$group} AS group_key, COALESCE( SUM( amount ), 0 ) AS total, COUNT(*) AS row_count FROM {$table} WHERE {$where_sql} GROUP BY {$group}";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql );
			$out  = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$out[ (string) $row->group_key ] = array(
					'total' => (int) $row->total,
					'count' => (int) $row->row_count,
				);
			}
			return $out;
		}

		if ( 'count' === $mode || 'sum' === $mode ) {
			$select = 'count' === $mode ? 'COUNT(*)' : 'COALESCE( SUM( amount ), 0 )';
			$sql    = "SELECT {$select} FROM {$table} WHERE {$where_sql}";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql );
		}

		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$params[] = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$params[] = max( 0, (int) ( $args['offset'] ?? 0 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, item_id, entry_type, amount, note, reason, reference, hold_id, created_at FROM {$table} WHERE {$where_sql} ORDER BY id {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		return is_array( $rows ) ? $rows : array();
	}
}
