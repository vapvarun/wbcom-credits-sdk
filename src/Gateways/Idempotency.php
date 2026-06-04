<?php
/**
 * Idempotency — webhook event-ID dedupe to prevent duplicate top-ups.
 *
 * Payment providers retry webhooks aggressively. Stripe replays for up to
 * 3 days on non-2xx responses; PayPal replays on a fixed schedule. Without
 * dedupe, a flaky network or slow database write can credit the same
 * payment two or more times.
 *
 * Storage moved from a per-slug option ring to a dedicated table with a
 * UNIQUE key (see {@see Processed_Events}). The option ring was a
 * read-modify-write: two concurrent deliveries of the same event could
 * both pass `is_processed()` and both credit. The table makes the claim
 * atomic — exactly one delivery wins the `INSERT IGNORE`.
 *
 * This class is now a thin, backward-compatible facade over
 * {@see Processed_Events} so existing callers (`is_processed()` /
 * `mark_processed()`) keep working while gaining the atomic guarantee.
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
	 * Cheap existence pre-check.
	 *
	 * NOTE: this is NOT a standalone guard. Two concurrent deliveries can
	 * both see "not processed" here before either claims. The atomic guard
	 * is {@see mark_processed()}'s return value — callers must claim FIRST
	 * and only proceed to credit when the claim returns true.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $gateway  Gateway id.
	 * @param string $event_id Provider-side event ID.
	 * @return bool
	 */
	public static function is_processed( string $slug, string $gateway, string $event_id ): bool {
		return Processed_Events::exists( $slug, $gateway, $event_id );
	}

	/**
	 * Atomically claim an event as processed.
	 *
	 * Returns TRUE only when this call was the first to record the event
	 * (it owns the event — proceed to credit). Returns FALSE when the
	 * event was already recorded (a duplicate delivery — stop) or the
	 * event id is empty.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $gateway  Gateway id.
	 * @param string $event_id Provider-side event ID.
	 * @return bool True if recorded for the first time.
	 */
	public static function mark_processed( string $slug, string $gateway, string $event_id ): bool {
		return Processed_Events::claim( $slug, $gateway, $event_id );
	}

	/**
	 * Reset the processed-events store for a (slug, gateway) pair.
	 *
	 * Retained for backward compatibility with callers/tests written
	 * against the pre-1.3.1 option-ring API. Now delegates to the
	 * table-backed store.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $gateway Gateway id.
	 * @return void
	 */
	public static function reset_for_tests( string $slug, string $gateway ): void {
		Processed_Events::reset_for_tests( $slug, $gateway );
	}
}
