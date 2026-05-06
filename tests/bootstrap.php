<?php
/**
 * Test bootstrap — stub the small slice of WordPress that gateway helpers
 * touch (options API, sanitize_key) so unit tests can exercise the SDK
 * without spinning up a full WP test scaffold.
 *
 * Helpers under test (Idempotency, Pending_Checkouts, Signature_Verifier,
 * Gateway_Event) are intentionally side-effect-thin so this stub is small.
 * Anything that needs $wpdb, REST routing, or hooks belongs in a WP-test
 * integration suite, not here.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// In-memory option store used by the helpers under test.
global $wbcom_credits_test_options;
$wbcom_credits_test_options = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		global $wbcom_credits_test_options;
		return $wbcom_credits_test_options[ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = false ): bool {
		global $wbcom_credits_test_options;
		$wbcom_credits_test_options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		global $wbcom_credits_test_options;
		unset( $wbcom_credits_test_options[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $s ): string {
		return trim( strip_tags( $s ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ): string {
		return (string) json_encode( $value, $flags, $depth );
	}
}

// Load classes under test. The SDK loader is pure PHP — no WP needed.
require_once __DIR__ . '/../src/Gateways/Idempotency.php';
require_once __DIR__ . '/../src/Gateways/Pending_Checkouts.php';
require_once __DIR__ . '/../src/Gateways/Signature_Verifier.php';
require_once __DIR__ . '/../src/Gateways/Gateway_Event.php';
