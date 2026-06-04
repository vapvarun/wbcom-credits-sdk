<?php
/**
 * Test_Gateway — a minimal concrete Abstract_Gateway for unit tests.
 *
 * Exercises the shared orchestration (idempotency claim-then-act, refund
 * accounting, event firing) without hitting any real provider HTTP API.
 * normalize_event() is driven directly by the test by passing already-shaped
 * Gateway_Event payloads through a tiny "echo" translation.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways\Fixtures;

use Wbcom\Credits\Gateways\Abstract_Gateway;
use Wbcom\Credits\Gateways\Gateway_Event;

final class Test_Gateway extends Abstract_Gateway {

	public function get_id(): string {
		return 'teststripe';
	}

	public function get_label(): string {
		return 'Test Gateway';
	}

	public function is_available(): bool {
		return true;
	}

	public function get_settings_fields(): array {
		return array();
	}

	public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD', ?string $return_url = null ): string {
		return 'https://example.test/checkout';
	}

	public function verify_signature( string $raw_body, array $headers ): bool {
		return true;
	}

	/**
	 * Translate a test payload directly into a Gateway_Event. The test
	 * passes the normalized fields under a `_event` key.
	 */
	public function normalize_event( array $payload ): ?Gateway_Event {
		if ( empty( $payload['_event'] ) || ! is_array( $payload['_event'] ) ) {
			return null;
		}
		$e = $payload['_event'];
		return new Gateway_Event(
			type: (string) ( $e['type'] ?? '' ),
			event_id: (string) ( $e['event_id'] ?? '' ),
			session_id: (string) ( $e['session_id'] ?? '' ),
			amount_cents: (int) ( $e['amount_cents'] ?? 0 ),
			currency: (string) ( $e['currency'] ?? 'USD' ),
			raw: $payload,
			provider_ref: (string) ( $e['provider_ref'] ?? '' )
		);
	}

	public function refund( string $slug, string $session_id, ?int $amount_cents = null ): bool {
		return true;
	}
}
