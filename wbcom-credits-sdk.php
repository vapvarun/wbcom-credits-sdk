<?php
/**
 * Wbcom Credits SDK — Reusable credit engine for WordPress plugins.
 *
 * Provides an append-only ledger, hold/deduct/refund lifecycle, payment
 * adapter registry (WooCommerce, PMPro, MemberPress), REST API, and admin UI.
 * Any Wbcom plugin bundles this SDK and registers via the hook below.
 *
 * @package Wbcom\Credits
 * @version 1.1.0
 * @license GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

// Include the autoloader only once across all bundled copies.
if ( ! defined( 'WBCOM_CREDITS_SDK_AUTOLOADER_LOADED' ) ) {
	define( 'WBCOM_CREDITS_SDK_AUTOLOADER_LOADED', true );
	if ( ! class_exists( '\\Wbcom\\Credits\\Versions' ) ) {
		require_once __DIR__ . '/src/Versions.php';
		require_once __DIR__ . '/src/Registry.php';
		require_once __DIR__ . '/src/Ledger.php';
		require_once __DIR__ . '/src/Credits.php';
		require_once __DIR__ . '/src/Consumer.php';
		require_once __DIR__ . '/src/REST.php';
		require_once __DIR__ . '/src/Template.php';
		require_once __DIR__ . '/src/Adapters/AdapterInterface.php';
		require_once __DIR__ . '/src/Adapters/AdapterRegistry.php';
		require_once __DIR__ . '/src/Adapters/WooCommerce.php';
		require_once __DIR__ . '/src/Adapters/WooSubscriptions.php';
		require_once __DIR__ . '/src/Adapters/WooMemberships.php';
		require_once __DIR__ . '/src/Adapters/PMPro.php';
		require_once __DIR__ . '/src/Adapters/MemberPress.php';
		require_once __DIR__ . '/src/Gateways/GatewayInterface.php';
	}
}

// Only set up hooks if WordPress is available and this version hasn't registered.
if ( ! function_exists( 'wbcom_credits_sdk_register_1_1_0' ) && function_exists( 'add_action' ) ) {

	add_action( 'after_setup_theme', array( '\\Wbcom\\Credits\\Versions', 'initialize_latest_version' ), 1, 0 );
	add_action( 'after_setup_theme', 'wbcom_credits_sdk_register_1_1_0', 0, 0 );

	/**
	 * Register this version of the SDK.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	function wbcom_credits_sdk_register_1_1_0(): void {
		$versions = \Wbcom\Credits\Versions::instance();
		$versions->register( '1.1.0', 'wbcom_credits_sdk_initialize_1_1_0' );
	}

	/**
	 * Initialize this version of the SDK.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	function wbcom_credits_sdk_initialize_1_1_0(): void {
		define( 'WBCOM_CREDITS_SDK_VERSION', '1.1.0' );
		define( 'WBCOM_CREDITS_SDK_PATH', __DIR__ );

		// Fire the registry hook — consuming plugins register here.
		do_action( 'wbcom_credits_sdk_registry', \Wbcom\Credits\Registry::instance() );

		// Boot all registered plugins.
		\Wbcom\Credits\Registry::instance()->boot_all();
	}

	// Fallback: initialize if after_setup_theme already fired (late include).
	if ( did_action( 'after_setup_theme' ) && ! doing_action( 'after_setup_theme' ) && ! defined( 'WBCOM_CREDITS_SDK_VERSION' ) ) {
		wbcom_credits_sdk_register_1_1_0();
		\Wbcom\Credits\Versions::initialize_latest_version();
	}
}
