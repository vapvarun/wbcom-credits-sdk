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
 * Each entry has a TTL (default 24 h). Expired entries are pruned on
 * read so the store stays small without a cron job.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Per-slug map of session_id => expected payment metadata.
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
	 * Build the per-slug option key.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	private static function option_key( string $slug ): string {
		return sprintf( 'wbcom_credits_pending_checkouts_%s', sanitize_key( $slug ) );
	}

	/**
	 * Store a pending checkout entry.
	 *
	 * @param string $slug        Plugin slug.
	 * @param string $session_id  Provider-side session/order id.
	 * @param array{
	 *     gateway:string,
	 *     user_id:int,
	 *     credits:int,
	 *     price_cents:int,
	 *     currency:string,
	 * } $payload Expected payment metadata.
	 * @param int    $ttl_seconds Optional override for TTL.
	 * @return void
	 */
	public static function put( string $slug, string $session_id, array $payload, int $ttl_seconds = self::DEFAULT_TTL ): void {
		if ( '' === $session_id ) {
			return;
		}
		$store = self::read( $slug );

		$store[ $session_id ] = array(
			'gateway'     => sanitize_key( (string) ( $payload['gateway'] ?? '' ) ),
			'user_id'     => (int) ( $payload['user_id'] ?? 0 ),
			'credits'     => (int) ( $payload['credits'] ?? 0 ),
			'price_cents' => (int) ( $payload['price_cents'] ?? 0 ),
			'currency'    => strtoupper( sanitize_text_field( (string) ( $payload['currency'] ?? 'USD' ) ) ),
			'expires_at'  => time() + max( 60, $ttl_seconds ),
		);

		self::write( $slug, $store );
	}

	/**
	 * Look up a pending checkout entry by session id.
	 *
	 * Returns null when the session is unknown or expired.
	 *
	 * @param string $slug       Plugin slug.
	 * @param string $session_id Provider-side session/order id.
	 * @return array{gateway:string,user_id:int,credits:int,price_cents:int,currency:string}|null
	 */
	public static function get( string $slug, string $session_id ): ?array {
		if ( '' === $session_id ) {
			return null;
		}
		$store = self::read( $slug );
		$entry = $store[ $session_id ] ?? null;
		if ( ! is_array( $entry ) ) {
			return null;
		}
		if ( (int) ( $entry['expires_at'] ?? 0 ) < time() ) {
			unset( $store[ $session_id ] );
			self::write( $slug, $store );
			return null;
		}
		// Strip storage-only fields before returning.
		unset( $entry['expires_at'] );
		return $entry;
	}

	/**
	 * Delete a session entry. Idempotent — call after a successful topup.
	 *
	 * @param string $slug       Plugin slug.
	 * @param string $session_id Provider-side session/order id.
	 * @return void
	 */
	public static function forget( string $slug, string $session_id ): void {
		if ( '' === $session_id ) {
			return;
		}
		$store = self::read( $slug );
		if ( isset( $store[ $session_id ] ) ) {
			unset( $store[ $session_id ] );
			self::write( $slug, $store );
		}
	}

	/**
	 * Read and prune the store in one pass.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string, array{gateway:string,user_id:int,credits:int,price_cents:int,currency:string,expires_at:int}>
	 */
	private static function read( string $slug ): array {
		$raw = get_option( self::option_key( $slug ), array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$now    = time();
		$pruned = array();
		foreach ( $raw as $sid => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( (int) ( $entry['expires_at'] ?? 0 ) >= $now ) {
				$pruned[ (string) $sid ] = $entry;
			}
		}
		return $pruned;
	}

	/**
	 * Write the (pruned) store back.
	 *
	 * @param string $slug  Plugin slug.
	 * @param array  $store Store contents.
	 * @return void
	 */
	private static function write( string $slug, array $store ): void {
		update_option( self::option_key( $slug ), $store, false );
	}

	/**
	 * Reset for tests.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function reset_for_tests( string $slug ): void {
		delete_option( self::option_key( $slug ) );
	}
}
