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
	 * Default TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	private const DEFAULT_TTL = 86400;

	/**
	 * How many stored entries one put() may inspect while sweeping expired ones.
	 *
	 * @var int
	 */
	private const SWEEP_BATCH = 50;

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
				'expires_at'  => $expires_at,
			),
			false
		);

		self::sweep_expired( $slug, $key, $expires_at );
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

		// The index lists every live entry's key; it is the enumeration
		// source, not the expiry source of truth — an entry's own
		// expires_at (same field get() checks) decides staleness, since
		// the two can disagree (e.g. a test, or a future caller, pokes the
		// entry directly).
		$index = get_option( self::entry_prefix( $slug ) . 'index', array() );
		foreach ( ( is_array( $index ) ? $index : array() ) as $key => $_unused_index_expiry ) {
			$entry = get_option( (string) $key, null );
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
	 * Delete a bounded batch of expired entries.
	 *
	 * A buyer who never returns leaves an entry behind. The entries' keys and
	 * expiry times are listed in one small index option; each put() records its
	 * entry there and deletes up to SWEEP_BATCH expired ones. The index is the
	 * only read-modify-write left, and it only drives cleanup: losing an index
	 * row to a race leaves one abandoned option behind, it can never lose a live
	 * checkout, which is read by its own key.
	 *
	 * @param string $slug      Plugin slug.
	 * @param string $key       Entry option just written ('' for none).
	 * @param int    $expires_at Its expiry.
	 * @return void
	 */
	private static function sweep_expired( string $slug, string $key = '', int $expires_at = 0 ): void {
		$index_key = self::entry_prefix( $slug ) . 'index';
		$index     = get_option( $index_key, array() );
		$index     = is_array( $index ) ? $index : array();

		$now     = time();
		$checked = 0;
		foreach ( $index as $entry_key => $entry_expires ) {
			if ( $checked >= self::SWEEP_BATCH ) {
				break;
			}
			++$checked;
			if ( (int) $entry_expires < $now || ! is_array( get_option( (string) $entry_key, null ) ) ) {
				delete_option( (string) $entry_key );
				unset( $index[ $entry_key ] );
			}
		}

		if ( '' !== $key ) {
			$index[ $key ] = $expires_at;
		}

		update_option( $index_key, $index, false );
	}

	/**
	 * Reset for tests.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function reset_for_tests( string $slug ): void {
		$index_key = self::entry_prefix( $slug ) . 'index';
		$index     = get_option( $index_key, array() );
		foreach ( array_keys( is_array( $index ) ? $index : array() ) as $entry_key ) {
			delete_option( (string) $entry_key );
		}
		delete_option( $index_key );
		delete_option( self::legacy_key( $slug ) );
	}
}
