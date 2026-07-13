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

// Normally defined by wbcom-credits-sdk.php's version-initializer, which
// only runs on the 'after_setup_theme' hook in a real WP request. Tests
// never fire that hook, so the asset-registration code in Registry.php
// (which references these) needs them defined here instead.
if ( ! defined( 'WBCOM_CREDITS_SDK_VERSION' ) ) {
	define( 'WBCOM_CREDITS_SDK_VERSION', '1.3.0' );
}
if ( ! defined( 'WBCOM_CREDITS_SDK_PATH' ) ) {
	define( 'WBCOM_CREDITS_SDK_PATH', dirname( __DIR__ ) );
}

// $wpdb output-format constants used by get_row()/get_results().
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
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

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $s, ?string $domain = null ): void {
		echo esc_html( $s );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $s, ?string $domain = null ): void {
		echo esc_attr( $s );
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, bool $echo = true ): string {
		$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, bool $echo = true ): string {
		$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
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

// Script registration API — needed by the reusable JS checkout helper
// (Registry::boot_all() registers + localizes the 'wbcom-credits-checkout'
// handle on 'wp_enqueue_scripts'). Minimal shim mirroring WP_Scripts'
// externally-visible behaviour: register/enqueue state + localized data.
global $wbcom_credits_test_scripts;
$wbcom_credits_test_scripts = array();

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( string $handle, string $src, array $deps = array(), $ver = false, bool $in_footer = false ): bool {
		global $wbcom_credits_test_scripts;
		// Mirror WP_Scripts::add(): re-registering an already-registered
		// handle is a no-op (returns false, existing entry incl. any
		// localized 'data' is left untouched) rather than resetting it.
		if ( isset( $wbcom_credits_test_scripts[ $handle ] ) ) {
			return false;
		}
		$wbcom_credits_test_scripts[ $handle ] = array(
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
			'enqueued'  => false,
			'data'      => array(),
		);
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, bool $in_footer = false ): void {
		global $wbcom_credits_test_scripts;
		if ( ! isset( $wbcom_credits_test_scripts[ $handle ] ) ) {
			wp_register_script( $handle, $src, $deps, $ver, $in_footer );
		}
		$wbcom_credits_test_scripts[ $handle ]['enqueued'] = true;
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( string $handle, string $list = 'enqueued' ): bool {
		global $wbcom_credits_test_scripts;
		if ( ! isset( $wbcom_credits_test_scripts[ $handle ] ) ) {
			return false;
		}
		if ( 'registered' === $list ) {
			return true;
		}
		return (bool) $wbcom_credits_test_scripts[ $handle ]['enqueued'];
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
		global $wbcom_credits_test_scripts;
		if ( ! isset( $wbcom_credits_test_scripts[ $handle ] ) ) {
			return false;
		}
		$existing = $wbcom_credits_test_scripts[ $handle ]['data']['data'] ?? '';
		$wbcom_credits_test_scripts[ $handle ]['data']['data'] = $existing . 'var ' . $object_name . ' = ' . wp_json_encode( $l10n ) . ';';
		return true;
	}
}

if ( ! class_exists( 'WP_Scripts' ) ) {
	class WP_Scripts {
		public function get_data( string $handle, string $key ) {
			global $wbcom_credits_test_scripts;
			return $wbcom_credits_test_scripts[ $handle ]['data'][ $key ] ?? false;
		}
	}
}

if ( ! function_exists( 'wp_scripts' ) ) {
	function wp_scripts(): WP_Scripts {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new WP_Scripts();
		}
		return $instance;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'http://example.org/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '-1' ): string {
		return 'test-nonce-' . md5( $action );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'http://example.org/wp-content/plugins/wbcom-credits-sdk/' . ltrim( $path, '/' );
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

// Shared $wpdb for table-backed helpers (Processed_Events, Transaction_Log,
// Ledger). Individual tests may replace it with a fresh FakeWpdb in setUp().
global $wpdb;
$wpdb = new \Wbcom\Credits\Tests\Support\FakeWpdb();

// Load classes under test. The SDK loader is pure PHP — no WP needed.
require_once __DIR__ . '/../src/Versions.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/Credits.php';
require_once __DIR__ . '/../src/Registry.php';
require_once __DIR__ . '/../src/Gateways/GatewayInterface.php';
require_once __DIR__ . '/../src/Gateways/Gateway_Event.php';
require_once __DIR__ . '/../src/Gateways/Processed_Events.php';
require_once __DIR__ . '/../src/Gateways/Idempotency.php';
require_once __DIR__ . '/../src/Gateways/Pending_Checkouts.php';
require_once __DIR__ . '/../src/Gateways/Signature_Verifier.php';
require_once __DIR__ . '/../src/Gateways/Transaction_Log.php';
require_once __DIR__ . '/../src/Gateways/Abstract_Gateway.php';
require_once __DIR__ . '/../src/Gateways/Stripe.php';
require_once __DIR__ . '/../src/Gateways/Pricing.php';

// Adapters — the production loader (wbcom-credits-sdk.php) maps these by
// class name rather than by PSR-4 filename convention because the adapter
// class names (e.g. WooCommerceAdapter) intentionally don't match their
// filenames (e.g. WooCommerce.php). Composer's PSR-4 autoloader can't
// resolve that mismatch, so Registry::boot_all() — which unconditionally
// constructs Adapters\AdapterRegistry — needs these required explicitly
// here too, the same way the real bootstrap does.
require_once __DIR__ . '/../src/Adapters/AdapterInterface.php';
require_once __DIR__ . '/../src/Adapters/WooCommerce.php';
require_once __DIR__ . '/../src/Adapters/WooSubscriptions.php';
require_once __DIR__ . '/../src/Adapters/WooMemberships.php';
require_once __DIR__ . '/../src/Adapters/PMPro.php';
require_once __DIR__ . '/../src/Adapters/MemberPress.php';
