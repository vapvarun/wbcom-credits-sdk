<?php
/**
 * Idempotency — webhook event-ID dedupe to prevent duplicate top-ups.
 *
 * Payment providers retry webhooks aggressively. Stripe replays for up to
 * 3 days on non-2xx responses; PayPal replays on a fixed schedule. Without
 * dedupe, a flaky network or slow database write can credit the same
 * payment two or more times.
 *
 * Storage is a per-slug option (`wbcom_credits_processed_events_{slug}`)
 * holding a FIFO ring of the last N processed event IDs. Reading is O(N)
 * but N is small (default 1000) and the ring lives in WP options cache,
 * so it is effectively free on every webhook.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook idempotency tracker.
 *
 * @since 1.2.0
 */
final class Idempotency {

	/**
	 * Maximum events retained per (slug, gateway) pair.
	 *
	 * @var int
	 */
	private const MAX_EVENTS = 1000;

	/**
	 * Build the per-slug option key.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $gateway Gateway id (e.g. 'stripe').
	 * @return string
	 */
	private static function option_key( string $slug, string $gateway ): string {
		return sprintf( 'wbcom_credits_processed_events_%s_%s', sanitize_key( $slug ), sanitize_key( $gateway ) );
	}

	/**
	 * Check whether the given event has already been processed for this gateway.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $gateway  Gateway id.
	 * @param string $event_id Provider-side event ID.
	 * @return bool
	 */
	public static function is_processed( string $slug, string $gateway, string $event_id ): bool {
		if ( '' === $event_id ) {
			return false;
		}
		$ring = get_option( self::option_key( $slug, $gateway ), array() );
		if ( ! is_array( $ring ) ) {
			return false;
		}
		return in_array( $event_id, $ring, true );
	}

	/**
	 * Record an event as processed. Returns true if newly stored, false if already present.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $gateway  Gateway id.
	 * @param string $event_id Provider-side event ID.
	 * @return bool True if recorded for the first time.
	 */
	public static function mark_processed( string $slug, string $gateway, string $event_id ): bool {
		if ( '' === $event_id ) {
			return false;
		}
		$key  = self::option_key( $slug, $gateway );
		$ring = get_option( $key, array() );
		if ( ! is_array( $ring ) ) {
			$ring = array();
		}
		if ( in_array( $event_id, $ring, true ) ) {
			return false;
		}
		$ring[] = $event_id;
		// FIFO trim so the ring never grows without bound.
		if ( count( $ring ) > self::MAX_EVENTS ) {
			$ring = array_slice( $ring, -self::MAX_EVENTS );
		}
		update_option( $key, $ring, false );
		return true;
	}

	/**
	 * Reset the ring for tests.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $gateway Gateway id.
	 * @return void
	 */
	public static function reset_for_tests( string $slug, string $gateway ): void {
		delete_option( self::option_key( $slug, $gateway ) );
	}
}
