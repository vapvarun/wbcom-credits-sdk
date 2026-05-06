<?php
/**
 * Transaction_Log — per-gateway audit trail of money-path events.
 *
 * The Credits Ledger answers "what is this user's balance and where did
 * it move." Transaction_Log answers "what did the gateway tell us, when
 * did it tell us, and which ledger row resulted." Without it, refund
 * webhooks have no way to find the original top-up's ledger row, and
 * support staff can't reconcile a Stripe refund against a credit
 * adjustment.
 *
 * Schema: append-only. The only physical update is `mark_refunded()`
 * which sets `refunded_cents` on a previously-completed checkout row
 * so the running refund total stays at most equal to the captured
 * total. Every refund is also logged as its own row so we keep a
 * full event history.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only gateway transaction log.
 *
 * @since 1.2.0
 */
final class Transaction_Log {

	public const KIND_CHECKOUT = 'checkout';
	public const KIND_REFUND   = 'refund';

	/**
	 * Resolve the table name for a given consumer prefix.
	 *
	 * @param string $prefix Consumer prefix (e.g. 'wbam', 'wcb').
	 * @return string
	 */
	public static function table_name( string $prefix ): string {
		global $wpdb;
		return $wpdb->prefix . sanitize_key( $prefix ) . '_credit_gateway_log';
	}

	/**
	 * Create the table if it doesn't exist. Called from Registry::boot_all().
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
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Insert a checkout row when a top-up completes.
	 *
	 * @param array{
	 *     slug:string, gateway:string, session_id:string, event_id:string,
	 *     user_id:int, credits:int, amount_cents:int, currency:string, ledger_id:int
	 * } $row Row data.
	 * @return int Newly inserted row id (0 on failure).
	 */
	public static function insert_checkout( array $row ): int {
		global $wpdb;
		$table = self::table_name( self::resolve_prefix( $row['slug'] ?? '' ) );
		$ok    = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'slug'         => sanitize_key( (string) ( $row['slug'] ?? '' ) ),
				'gateway'      => sanitize_key( (string) ( $row['gateway'] ?? '' ) ),
				'kind'         => self::KIND_CHECKOUT,
				'session_id'   => (string) ( $row['session_id'] ?? '' ),
				'event_id'     => (string) ( $row['event_id'] ?? '' ),
				'user_id'      => (int) ( $row['user_id'] ?? 0 ),
				'credits'      => (int) ( $row['credits'] ?? 0 ),
				'amount_cents' => (int) ( $row['amount_cents'] ?? 0 ),
				'currency'     => strtoupper( (string) ( $row['currency'] ?? 'USD' ) ),
				'ledger_id'    => (int) ( $row['ledger_id'] ?? 0 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Insert a refund row linked to a previously-recorded checkout.
	 *
	 * @param array{
	 *     slug:string, gateway:string, session_id:string, event_id:string,
	 *     user_id:int, credits:int, amount_cents:int, currency:string,
	 *     ledger_id:int, parent_id:int
	 * } $row Row data.
	 * @return int
	 */
	public static function insert_refund( array $row ): int {
		global $wpdb;
		$table = self::table_name( self::resolve_prefix( $row['slug'] ?? '' ) );
		$ok    = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'slug'         => sanitize_key( (string) ( $row['slug'] ?? '' ) ),
				'gateway'      => sanitize_key( (string) ( $row['gateway'] ?? '' ) ),
				'kind'         => self::KIND_REFUND,
				'session_id'   => (string) ( $row['session_id'] ?? '' ),
				'event_id'     => (string) ( $row['event_id'] ?? '' ),
				'user_id'      => (int) ( $row['user_id'] ?? 0 ),
				'credits'      => (int) ( $row['credits'] ?? 0 ),
				'amount_cents' => (int) ( $row['amount_cents'] ?? 0 ),
				'currency'     => strtoupper( (string) ( $row['currency'] ?? 'USD' ) ),
				'ledger_id'    => (int) ( $row['ledger_id'] ?? 0 ),
				'parent_id'    => (int) ( $row['parent_id'] ?? 0 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Find the checkout row for a given session id (gateway+session is unique).
	 *
	 * @return array<string, mixed>|null Row or null.
	 */
	public static function find_checkout( string $slug, string $gateway, string $session_id ): ?array {
		global $wpdb;
		$table = self::table_name( self::resolve_prefix( $slug ) );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE slug=%s AND gateway=%s AND kind=%s AND session_id=%s LIMIT 1",
				sanitize_key( $slug ),
				sanitize_key( $gateway ),
				self::KIND_CHECKOUT,
				$session_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Increment refunded_cents on a checkout row. Caller must guarantee
	 * the increment does not push refunded_cents above amount_cents.
	 *
	 * @param string $slug
	 * @param int    $checkout_row_id
	 * @param int    $delta_cents
	 * @return void
	 */
	public static function add_refunded_amount( string $slug, int $checkout_row_id, int $delta_cents ): void {
		global $wpdb;
		$table = self::table_name( self::resolve_prefix( $slug ) );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET refunded_cents = refunded_cents + %d WHERE id=%d AND kind=%s",
				max( 0, $delta_cents ),
				$checkout_row_id,
				self::KIND_CHECKOUT
			)
		);
	}

	/**
	 * Resolve consumer DB prefix from slug via the SDK Registry.
	 *
	 * @param string $slug
	 * @return string Prefix or empty string when slug is unknown.
	 */
	private static function resolve_prefix( string $slug ): string {
		$config = \Wbcom\Credits\Registry::instance()->get( $slug );
		return is_array( $config ) ? (string) ( $config['prefix'] ?? '' ) : '';
	}
}
