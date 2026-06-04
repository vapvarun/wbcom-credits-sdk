<?php
/**
 * Processed_Events — atomic webhook event-ID dedupe via a UNIQUE constraint.
 *
 * Payment providers retry webhooks aggressively. Stripe replays for up to
 * 3 days on non-2xx responses; PayPal replays on a fixed schedule. Two
 * concurrent deliveries of the SAME event must credit at most once.
 *
 * The atomicity guarantee lives in the database, not in PHP. Each (slug,
 * gateway, event_id) tuple has a UNIQUE key. {@see claim()} performs a
 * single `INSERT IGNORE` and reports success only when a row was actually
 * inserted — so exactly one of N racing webhook deliveries wins the claim
 * and the rest are told "already processed." This removes the read-modify-
 * write TOCTOU window the previous option-ring had, where two deliveries
 * could both pass an in-array() check and both credit.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.3.1
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic processed-events store.
 *
 * @since 1.3.1
 */
final class Processed_Events {

	/**
	 * Resolve the table name for a given consumer prefix.
	 *
	 * @param string $prefix Consumer prefix (e.g. 'wbam', 'wcb').
	 * @return string
	 */
	public static function table_name( string $prefix ): string {
		global $wpdb;
		return $wpdb->prefix . sanitize_key( $prefix ) . '_credit_processed_events';
	}

	/**
	 * Create the table if it doesn't exist. Called from Registry::boot_all().
	 *
	 * The UNIQUE key over (slug, gateway, event_id) is the entire point of
	 * this table — it is what makes the duplicate-delivery guard atomic.
	 *
	 * @param string $prefix Consumer prefix.
	 * @return void
	 */
	public static function maybe_create_table( string $prefix ): void {
		global $wpdb;
		$table  = self::table_name( $prefix );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $table === $exists ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			gateway VARCHAR(32) NOT NULL,
			event_id VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_event (slug, gateway, event_id)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Atomically claim an event for processing.
	 *
	 * Returns TRUE only when THIS call inserted the row (i.e. it is the
	 * first delivery to claim the event). Returns FALSE when the UNIQUE
	 * constraint rejected the insert (the event was already claimed by a
	 * concurrent or earlier delivery) or when the event id is empty.
	 *
	 * Callers MUST treat a TRUE return as "you own this event, proceed to
	 * credit" and a FALSE return as "stop, it's already handled."
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $gateway  Gateway id.
	 * @param string $event_id Provider-side event ID.
	 * @return bool True when newly claimed, false when already processed / invalid.
	 */
	public static function claim( string $slug, string $gateway, string $event_id ): bool {
		if ( '' === $event_id ) {
			return false;
		}
		global $wpdb;
		$table = self::table_name( self::resolve_prefix( $slug ) );

		// INSERT IGNORE: the UNIQUE key makes a duplicate a silent no-op
		// (0 rows affected) instead of an error. rows_affected === 1 means
		// this delivery won the race; anything else means it lost.
		$ok = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (slug, gateway, event_id) VALUES (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_key( $slug ),
				sanitize_key( $gateway ),
				$event_id
			)
		);

		return ( false !== $ok ) && 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Cheap existence pre-check. NOT a guard on its own — callers must rely
	 * on {@see claim()}'s return value for the real claim-then-act guarantee.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $gateway  Gateway id.
	 * @param string $event_id Provider-side event ID.
	 * @return bool
	 */
	public static function exists( string $slug, string $gateway, string $event_id ): bool {
		if ( '' === $event_id ) {
			return false;
		}
		global $wpdb;
		$table = self::table_name( self::resolve_prefix( $slug ) );
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE slug=%s AND gateway=%s AND event_id=%s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_key( $slug ),
				sanitize_key( $gateway ),
				$event_id
			)
		);
		return null !== $found;
	}

	/**
	 * Delete all processed-event rows for a (slug, gateway) pair.
	 *
	 * Test/cleanup helper. Safe in production (scoped delete) but only the
	 * SDK's own tests call it.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $gateway Gateway id.
	 * @return void
	 */
	public static function reset_for_tests( string $slug, string $gateway ): void {
		global $wpdb;
		$table = self::table_name( self::resolve_prefix( $slug ) );
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'slug'    => sanitize_key( $slug ),
				'gateway' => sanitize_key( $gateway ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Resolve consumer DB prefix from slug via the SDK Registry.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Prefix, or the slug itself when the registry has no
	 *                matching config (keeps tests + edge cases functional).
	 */
	private static function resolve_prefix( string $slug ): string {
		$config = \Wbcom\Credits\Registry::instance()->get( $slug );
		$prefix = is_array( $config ) ? (string) ( $config['prefix'] ?? '' ) : '';
		return '' !== $prefix ? $prefix : sanitize_key( $slug );
	}
}
