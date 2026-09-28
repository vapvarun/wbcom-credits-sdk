<?php
/**
 * Pending checkouts — cross-check storage to defeat amount/currency tampering.
 *
 * When `Stripe::create_checkout()` builds a session, it stores
 * (session_id, slug, user_id, credits, price_cents, currency, gateway)
 * here. When the matching webhook arrives, the gateway looks the session
 * up by id and rejects the topup if the webhook payload disagrees with
 * the stored values. Stripe's hosted Checkout already prevents amount
 * tampering at the redirect, but storing our expectation lets us catch
 * cases where the webhook signature is valid but routes to a different
 * session or the gateway is misconfigured.
 *
 * Each entry is its own option (since 1.7.2). They used to share one
 * option per slug that every put() and forget() read, modified and wrote
 * back, so two members checking out at the same moment could overwrite each
 * other's entry; that buyer's webhook and return claim then found no pending
 * checkout (404 unknown_session) and a paid purchase was never credited.
 * Entries left in the pre-1.7.2 shared option are still read and removed.
 *
 * Each entry has a TTL (default 24 h). Expired entries are deleted when read,
 * and a bounded sweep on put() removes abandoned ones without a cron job.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Expected payment metadata per checkout session.
 *
 * @since 1.2.0
 */
final class Pending_Checkouts {

	/**
	 * Default TTL in seconds (7 days: delayed payment methods - SEPA, ACH,
	 * bank transfers - confirm days after the buyer leaves, and a webhook
	 * arriving after the entry is gone was answered unknown_session).
	 *
	 * @var int
	 */
	private const DEFAULT_TTL = 7 * 86400;

	/**
	 * How many stored entries one put() may inspect while sweeping expired ones.
	 *
	 * @var int
	 */
	private const SWEEP_BATCH = 50;

	/**
	 * Most entries one enumeration (for_user, coupon_holds) reads per slug. Entries
	 * live for days and leave when credited, so this is far above a real backlog.
	 *
	 * @var int
	 */
	private const SCAN_MAX = 1000;

	/**
	 * Option name prefix for one slug's entries.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	private static function entry_prefix( string $slug ): string {
		return sprintf( 'wbcom_credits_pc_%s_', sanitize_key( $slug ) );
	}

	/**
	 * Option name for one session's entry.
	 *
	 * @param string $slug       Plugin slug.
	 * @param string $session_id Provider session id.
	 * @return string
	 */
	private static function entry_key( string $slug, string $session_id ): string {
		return self::entry_prefix( $slug ) . md5( $session_id );
	}

	/**
	 * The pre-1.7.2 shared option, read for entries created before the upgrade.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	private static function legacy_key( string $slug ): string {
		return sprintf( 'wbcom_credits_pending_checkouts_%s', sanitize_key( $slug ) );
	}

	/**
	 * Order parts (Order::build()) waiting for the next put().
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $staged_order = null;

	/**
	 * Stage the order the next put() stores with its session.
	 *
	 * Gateways call put() from inside create_checkout(), after the provider
	 * returns the session id; staging keeps the order off the gateway
	 * interface, so custom gateways written before 1.9.0 keep working.
	 *
	 * @since 1.9.0
	 * @param array<string, mixed>|null $order Order parts, or null to clear.
	 * @return void
	 */
	public static function stage_order( ?array $order ): void {
		self::$staged_order = $order;
	}

	/**
	 * Unpaid checkouts using a coupon, started at or after $since.
	 *
	 * Entries staged before 1.9.2 carry no start time and are not counted.
	 *
	 * @since 1.9.2
	 * @param string $slug  Plugin slug.
	 * @param string $code  Upper-case coupon code.
	 * @param int    $since Unix time.
	 * @return int
	 */
	public static function coupon_holds( string $slug, string $code, int $since ): int {
		$now   = time();
		$count = 0;
		foreach ( self::keys( $slug, self::SCAN_MAX ) as $key ) {
			$entry = get_option( $key, null );
			if ( ! is_array( $entry ) || (int) ( $entry['expires_at'] ?? 0 ) < $now ) {
				continue;
			}
			if ( (int) ( $entry['created_at'] ?? 0 ) >= $since && strtoupper( (string) ( $entry['order']['coupon'] ?? '' ) ) === $code ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Store the expected payment for a session.
	 *
	 * @param string               $slug        Plugin slug.
	 * @param string               $session_id  Provider session id.
	 * @param array<string, mixed> $payload     gateway, user_id, credits, price_cents, currency.
	 * @param int                  $ttl_seconds Lifetime.
	 * @return void
	 */
	public static function put( string $slug, string $session_id, array $payload, int $ttl_seconds = self::DEFAULT_TTL ): void {
		if ( '' === $session_id ) {
			return;
		}

		$key        = self::entry_key( $slug, $session_id );
		$expires_at = time() + max( 60, $ttl_seconds );

		update_option(
			$key,
			array(
				// Stored alongside the other fields (not just implied by the option
				// key) because the key is session_id run through a one-way md5 -
				// for_user() enumerates entries via the sweep index and has no other
				// way to recover which session an entry belongs to.
				'session_id'  => $session_id,
				'gateway'     => sanitize_key( (string) ( $payload['gateway'] ?? '' ) ),
				'user_id'     => (int) ( $payload['user_id'] ?? 0 ),
				'credits'     => (int) ( $payload['credits'] ?? 0 ),
				'price_cents' => (int) ( $payload['price_cents'] ?? 0 ),
				'currency'    => strtoupper( sanitize_text_field( (string) ( $payload['currency'] ?? 'USD' ) ) ),
				'order'       => (array) ( self::$staged_order ?? array() ),
				'created_at'  => time(),
				'expires_at'  => $expires_at,
			),
			false
		);
		self::$staged_order = null;

		self::sweep_expired( $slug );
	}

	/**
	 * Read the expected payment for a session, or null when unknown or expired.
	 *
	 * @param string $slug       Plugin slug.
	 * @param string $session_id Provider session id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug, string $session_id ): ?array {
		if ( '' === $session_id ) {
			return null;
		}

		$key   = self::entry_key( $slug, $session_id );
		$entry = get_option( $key, null );

		if ( ! is_array( $entry ) ) {
			$legacy = get_option( self::legacy_key( $slug ), array() );
			$entry  = is_array( $legacy ) && is_array( $legacy[ $session_id ] ?? null ) ? $legacy[ $session_id ] : null;
			if ( null === $entry ) {
				return null;
			}
		}

		if ( (int) ( $entry['expires_at'] ?? 0 ) < time() ) {
			self::forget( $slug, $session_id );
			return null;
		}

		// Strip storage-only fields before returning.
		unset( $entry['expires_at'] );
		return $entry;
	}

	/**
	 * The oldest live pending checkouts for a slug, for the reconcile sweep.
	 *
	 * @since 1.9.0
	 * @param string $slug  Plugin slug.
	 * @param int    $limit Most to return.
	 * @return array<int, array{session_id: string, gateway: string}>
	 */
	public static function oldest( string $slug, int $limit ): array {
		$now = time();
		$out = array();
		// Expired entries the sweep has not reached yet take up slots, so read a little past $limit.
		foreach ( self::keys( $slug, $limit + self::SWEEP_BATCH ) as $key ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$entry = get_option( $key, null );
			if ( ! is_array( $entry ) || (int) ( $entry['expires_at'] ?? 0 ) < $now || '' === (string) ( $entry['session_id'] ?? '' ) ) {
				continue;
			}
			$out[] = array(
				'session_id' => (string) $entry['session_id'],
				'gateway'    => (string) ( $entry['gateway'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * All non-expired pending checkouts for one buyer, newest first.
	 *
	 * Lets a consumer show "awaiting payment" in its own wallet UI for a
	 * direct-gateway checkout that has not been credited yet (the webhook has
	 * not arrived, or the buyer has not returned to claim it) - the same
	 * visibility {@see Transaction_Log} already gives completed purchases.
	 *
	 * Ordering is by expiry, newest first: every put() without a custom TTL
	 * (the common case) computes expires_at from the same fixed default, so a
	 * later checkout always expires later. There is no created_at field to
	 * sort by instead, and adding one for this alone is not worth a storage
	 * shape change.
	 *
	 * @since 1.8.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @return array<int, array{session_id:string,gateway:string,user_id:int,credits:int,price_cents:int,currency:string}>
	 */
	public static function for_user( string $slug, int $user_id ): array {
		$now  = time();
		$rows = array();

		// An entry's own expires_at (the field get() checks) decides staleness.
		foreach ( self::keys( $slug, self::SCAN_MAX ) as $key ) {
			$entry = get_option( $key, null );
			if ( ! is_array( $entry ) || (int) ( $entry['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$expires_at = (int) ( $entry['expires_at'] ?? 0 );
			if ( $expires_at < $now ) {
				continue;
			}
			$rows[] = self::for_user_row( $entry, $expires_at );
		}

		// Pre-1.7.2 entries live in one shared option, keyed by session_id.
		$legacy = get_option( self::legacy_key( $slug ), array() );
		foreach ( ( is_array( $legacy ) ? $legacy : array() ) as $session_id => $entry ) {
			if ( ! is_array( $entry ) || (int) ( $entry['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$expires_at = (int) ( $entry['expires_at'] ?? 0 );
			if ( $expires_at < $now ) {
				continue;
			}
			$entry['session_id'] = (string) $session_id;
			$rows[]              = self::for_user_row( $entry, $expires_at );
		}

		usort( $rows, static fn( array $a, array $b ): int => $b['_sort'] <=> $a['_sort'] );

		return array_map(
			static function ( array $row ): array {
				unset( $row['_sort'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * Normalize one stored entry into the shape for_user() returns.
	 *
	 * @param array<string, mixed> $entry      Stored entry (already known to match the requested user).
	 * @param int                  $expires_at Entry expiry, used only for sort order.
	 * @return array{session_id:string,gateway:string,user_id:int,credits:int,price_cents:int,currency:string,_sort:int}
	 */
	private static function for_user_row( array $entry, int $expires_at ): array {
		return array(
			'session_id'  => (string) ( $entry['session_id'] ?? '' ),
			'gateway'     => (string) ( $entry['gateway'] ?? '' ),
			'user_id'     => (int) ( $entry['user_id'] ?? 0 ),
			'credits'     => (int) ( $entry['credits'] ?? 0 ),
			'price_cents' => (int) ( $entry['price_cents'] ?? 0 ),
			'currency'    => (string) ( $entry['currency'] ?? 'USD' ),
			'_sort'       => $expires_at,
		);
	}

	/**
	 * Remove a session's entry once it has been credited (or abandoned).
	 *
	 * @param string $slug       Plugin slug.
	 * @param string $session_id Provider session id.
	 * @return void
	 */
	public static function forget( string $slug, string $session_id ): void {
		if ( '' === $session_id ) {
			return;
		}

		delete_option( self::entry_key( $slug, $session_id ) );

		// A pre-1.7.2 entry lives in the shared option. Removing it is the
		// old read-modify-write, but only ever for legacy entries, so it
		// shrinks to nothing as those sessions complete or expire.
		$legacy = get_option( self::legacy_key( $slug ), null );
		if ( is_array( $legacy ) && isset( $legacy[ $session_id ] ) ) {
			unset( $legacy[ $session_id ] );
			if ( empty( $legacy ) ) {
				delete_option( self::legacy_key( $slug ) );
			} else {
				update_option( self::legacy_key( $slug ), $legacy, false );
			}
		}
	}

	/**
	 * Option names of a slug's pending entries, oldest first.
	 *
	 * Every entry is its own option, so the option table is the list: nothing has
	 * to be recorded anywhere when an entry is written, and two checkouts started
	 * in the same instant cannot lose each other's record (the shared index this
	 * replaces was a read-modify-write, and the hourly reconcile sweep reads it).
	 * The name is the prefix plus a 32-character md5, so matching on length also
	 * skips the pre-1.9.5 `index` option and another slug that shares this prefix.
	 * Served by the unique option_name index and bounded by LIMIT.
	 *
	 * @since 1.9.5
	 *
	 * @param string $slug  Plugin slug.
	 * @param int    $limit Most names to return.
	 * @return string[]
	 */
	private static function keys( string $slug, int $limit ): array {
		global $wpdb;

		$prefix = self::entry_prefix( $slug );
		$names  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND LENGTH(option_name) = %d ORDER BY option_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
				$wpdb->esc_like( $prefix ) . '%',
				strlen( $prefix ) + 32,
				max( 1, $limit )
			)
		);

		return is_array( $names ) ? array_map( 'strval', $names ) : array();
	}

	/**
	 * Delete a bounded batch of expired entries.
	 *
	 * A buyer who never returns leaves an entry behind. Each put() looks at the
	 * oldest SWEEP_BATCH entries (the ones most likely to have expired) and
	 * deletes those that are gone or past their expiry. It also removes the
	 * shared index option older versions kept.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	private static function sweep_expired( string $slug ): void {
		// Pre-1.9.5 index. Its entries are found by name now; drop it once seen.
		delete_option( self::entry_prefix( $slug ) . 'index' );

		$now = time();
		foreach ( self::keys( $slug, self::SWEEP_BATCH ) as $key ) {
			$entry = get_option( $key, null );
			if ( ! is_array( $entry ) || (int) ( $entry['expires_at'] ?? 0 ) < $now ) {
				delete_option( $key );
			}
		}
	}

	/**
	 * Reset for tests.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function reset_for_tests( string $slug ): void {
		foreach ( self::keys( $slug, 100000 ) as $key ) {
			delete_option( $key );
		}
		delete_option( self::entry_prefix( $slug ) . 'index' );
		delete_option( self::legacy_key( $slug ) );
	}
}
