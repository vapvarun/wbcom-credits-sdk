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

if ( ! function_exists( '__return_null' ) ) {
	function __return_null() {
		return null;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $v ): int {
		return abs( (int) $v );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $v ): string {
		return is_string( $v ) ? $v : '';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ): string {
		return is_string( $s ) ? trim( strip_tags( $s ) ) : '';
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function, $message, $version ): void {
		// Quiet in tests.
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		return array_merge( (array) $defaults, $args );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $s, ?string $domain = null ): string {
		return $s;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $s, string $ctx, ?string $domain = null ): string {
		return $s;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}

global $wbcom_credits_test_hooks, $wbcom_credits_test_routes;
$wbcom_credits_test_hooks  = array(
	'actions' => array(),
	'filters' => array(),
);
$wbcom_credits_test_routes = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		global $wbcom_credits_test_hooks;
		$wbcom_credits_test_hooks['actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		global $wbcom_credits_test_hooks;
		foreach ( $wbcom_credits_test_hooks['actions'][ $hook ] ?? array() as $cbs ) {
			foreach ( $cbs as [ $cb, $n ] ) {
				call_user_func_array( $cb, array_slice( $args, 0, $n ) );
			}
		}
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( string $hook, $callback = false ): bool {
		global $wbcom_credits_test_hooks;
		return ! empty( $wbcom_credits_test_hooks['actions'][ $hook ] );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		global $wbcom_credits_test_hooks;
		$wbcom_credits_test_hooks['filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ): mixed {
		global $wbcom_credits_test_hooks;
		foreach ( $wbcom_credits_test_hooks['filters'][ $hook ] ?? array() as $cbs ) {
			foreach ( $cbs as [ $cb, $n ] ) {
				$value = call_user_func_array( $cb, array_merge( array( $value ), array_slice( $args, 0, $n - 1 ) ) );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return false;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): bool {
		global $wbcom_credits_test_routes;
		$wbcom_credits_test_routes[ $namespace . $route ] = $args;
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		global $wbcom_credits_test_can;
		return (bool) ( $wbcom_credits_test_can ?? true );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		global $wbcom_credits_test_uid;
		return (int) ( $wbcom_credits_test_uid ?? 1 );
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $uid ): ?object {
		return $uid > 0 ? (object) array( 'ID' => $uid ) : null;
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $sql ): array {
		global $wpdb;
		if ( $wpdb instanceof \Wbcom\Credits\Tests\Support\FakeWpdb ) {
			$wpdb->record_create_table( is_array( $sql ) ? implode( ';', $sql ) : $sql );
		}
		return array();
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = array();
		public function set_param( string $k, $v ): void {
			$this->params[ $k ] = $v;
		}
		public function get_param( string $k ): mixed {
			return $this->params[ $k ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public mixed $data = null, public int $status = 200 ) {}
		public function get_data(): mixed {
			return $this->data;
		}
		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

require_once __DIR__ . '/Support/FakeWpdb.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Load classes under test. The SDK loader is pure PHP — no WP needed.
require_once __DIR__ . '/../src/Gateways/Idempotency.php';
require_once __DIR__ . '/../src/Gateways/Pending_Checkouts.php';
require_once __DIR__ . '/../src/Gateways/Signature_Verifier.php';
require_once __DIR__ . '/../src/Gateways/Gateway_Event.php';
require_once __DIR__ . '/../src/Versions.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/Credits.php';
require_once __DIR__ . '/../src/Registry.php';
require_once __DIR__ . '/../src/Gateways/Pricing.php';
