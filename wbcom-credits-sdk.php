<?php
/**
 * Wbcom Credits SDK — reusable credit engine for WordPress plugins.
 *
 * Append-only ledger, hold/deduct/refund lifecycle, payment-gateway adapters
 * (WooCommerce, WooSubscriptions, WooMemberships, PMPro, MemberPress),
 * direct payment gateways (Stripe, PayPal) with provider-initiated and
 * SDK-initiated refund support, REST API, and admin UI. Each consuming
 * plugin bundles this SDK as a git submodule and registers itself via the
 * `wbcom_credits_sdk_registry` hook.
 *
 * @package Wbcom\Credits
 * @version 1.2.0
 * @license GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

/*
 * ─── Class loader ────────────────────────────────────────────────────────
 *
 * Multiple plugins on the same site can each bundle their own copy of the
 * SDK. They run in load-order and any of them may reach this file first.
 *
 * The previous design used one boolean flag (WBCOM_CREDITS_SDK_AUTOLOADER_LOADED):
 * the first bootstrap to run set the flag and required its full class set;
 * every later bootstrap skipped entirely. That worked when every bundled
 * copy was identical — but the moment two consumers shipped at different
 * SDK versions (which is the normal state of a submodule pinned per
 * plugin), the older copy could win the race and the newer consumer would
 * fatal on "Class X not found" for any class added after that older
 * version.
 *
 * The new loader walks a class → file map. For each entry it loads the
 * file only when the class isn't already in memory. Order in the map
 * places dependencies first (Versions, Registry, Ledger) so a partial
 * load can still resolve required parents on its way to leaf classes.
 *
 * Each bootstrap is now a fill-in: it loads what's missing and no-ops on
 * what's already there. Older + newer copies coexist; the newer copy
 * fills in classes the older one didn't ship.
 */
$wbcom_credits_sdk_classes = array(
	'\\Wbcom\\Credits\\Versions'                          => __DIR__ . '/src/Versions.php',
	'\\Wbcom\\Credits\\Registry'                          => __DIR__ . '/src/Registry.php',
	'\\Wbcom\\Credits\\Ledger'                            => __DIR__ . '/src/Ledger.php',
	'\\Wbcom\\Credits\\Credits'                           => __DIR__ . '/src/Credits.php',
	'\\Wbcom\\Credits\\Consumer'                          => __DIR__ . '/src/Consumer.php',
	'\\Wbcom\\Credits\\REST'                              => __DIR__ . '/src/REST.php',
	'\\Wbcom\\Credits\\Template'                          => __DIR__ . '/src/Template.php',
	'\\Wbcom\\Credits\\Adapters\\AdapterInterface'        => __DIR__ . '/src/Adapters/AdapterInterface.php',
	'\\Wbcom\\Credits\\Adapters\\AdapterRegistry'         => __DIR__ . '/src/Adapters/AdapterRegistry.php',
	'\\Wbcom\\Credits\\Adapters\\WooCommerceAdapter'      => __DIR__ . '/src/Adapters/WooCommerce.php',
	'\\Wbcom\\Credits\\Adapters\\WooSubscriptionsAdapter' => __DIR__ . '/src/Adapters/WooSubscriptions.php',
	'\\Wbcom\\Credits\\Adapters\\WooMembershipsAdapter'   => __DIR__ . '/src/Adapters/WooMemberships.php',
	'\\Wbcom\\Credits\\Adapters\\PMProAdapter'            => __DIR__ . '/src/Adapters/PMPro.php',
	'\\Wbcom\\Credits\\Adapters\\MemberPressAdapter'      => __DIR__ . '/src/Adapters/MemberPress.php',
	// Gateway interfaces + helpers (load order matters: interface, DTO, helpers, abstract, concretes).
	'\\Wbcom\\Credits\\Gateways\\GatewayInterface'        => __DIR__ . '/src/Gateways/GatewayInterface.php',
	'\\Wbcom\\Credits\\Gateways\\Gateway_Event'           => __DIR__ . '/src/Gateways/Gateway_Event.php',
	'\\Wbcom\\Credits\\Gateways\\Idempotency'             => __DIR__ . '/src/Gateways/Idempotency.php',
	'\\Wbcom\\Credits\\Gateways\\Pending_Checkouts'       => __DIR__ . '/src/Gateways/Pending_Checkouts.php',
	'\\Wbcom\\Credits\\Gateways\\Signature_Verifier'      => __DIR__ . '/src/Gateways/Signature_Verifier.php',
	'\\Wbcom\\Credits\\Gateways\\Transaction_Log'         => __DIR__ . '/src/Gateways/Transaction_Log.php',
	'\\Wbcom\\Credits\\Gateways\\Abstract_Gateway'        => __DIR__ . '/src/Gateways/Abstract_Gateway.php',
	'\\Wbcom\\Credits\\Gateways\\Stripe'                  => __DIR__ . '/src/Gateways/Stripe.php',
	'\\Wbcom\\Credits\\Gateways\\PayPal'                  => __DIR__ . '/src/Gateways/PayPal.php',
	'\\Wbcom\\Credits\\Gateways\\Gateway_Registry'        => __DIR__ . '/src/Gateways/Gateway_Registry.php',
	'\\Wbcom\\Credits\\Gateways\\Webhook_Controller'      => __DIR__ . '/src/Gateways/Webhook_Controller.php',
);

foreach ( $wbcom_credits_sdk_classes as $wbcom_credits_sdk_class => $wbcom_credits_sdk_file ) {
	if ( class_exists( $wbcom_credits_sdk_class ) || interface_exists( $wbcom_credits_sdk_class ) ) {
		continue;
	}
	if ( file_exists( $wbcom_credits_sdk_file ) ) {
		require_once $wbcom_credits_sdk_file;
	}
}

unset( $wbcom_credits_sdk_classes, $wbcom_credits_sdk_class, $wbcom_credits_sdk_file );

/*
 * Backward-compatible flag.
 *
 * Earlier SDK releases used this define to gate loading. Some third-party
 * plugins may sniff for it to detect "the SDK is around". We keep defining
 * it for that sniff but it no longer controls the loader above.
 *
 * @deprecated 1.1.1 informational only.
 */
if ( ! defined( 'WBCOM_CREDITS_SDK_AUTOLOADER_LOADED' ) ) {
	define( 'WBCOM_CREDITS_SDK_AUTOLOADER_LOADED', true );
}

/*
 * ─── Version registration ────────────────────────────────────────────────
 *
 * Every shipped SDK version registers itself with `Versions` so the highest
 * available version on this request actually boots. Multiple bundled copies
 * can register; only the latest wins `initialize_latest_version()`.
 *
 * The function-name guard makes this file idempotent — re-including it
 * after the first run is a clean no-op.
 */
if ( ! function_exists( 'wbcom_credits_sdk_register_1_2_0' ) && function_exists( 'add_action' ) ) {

	add_action( 'after_setup_theme', array( '\\Wbcom\\Credits\\Versions', 'initialize_latest_version' ), 1, 0 );
	add_action( 'after_setup_theme', 'wbcom_credits_sdk_register_1_2_0', 0, 0 );

	/**
	 * Register this version with Versions::instance().
	 *
	 * @since 1.2.0
	 * @return void
	 */
	function wbcom_credits_sdk_register_1_2_0(): void {
		\Wbcom\Credits\Versions::instance()->register( '1.2.0', 'wbcom_credits_sdk_initialize_1_2_0' );
	}

	/**
	 * Initialize this version (called only if Versions picked it as latest).
	 *
	 * @since 1.2.0
	 * @return void
	 */
	function wbcom_credits_sdk_initialize_1_2_0(): void {
		if ( ! defined( 'WBCOM_CREDITS_SDK_VERSION' ) ) {
			define( 'WBCOM_CREDITS_SDK_VERSION', '1.2.0' );
		}
		if ( ! defined( 'WBCOM_CREDITS_SDK_PATH' ) ) {
			define( 'WBCOM_CREDITS_SDK_PATH', __DIR__ );
		}

		// Consuming plugins register their slug, prefix, and consumers here.
		do_action( 'wbcom_credits_sdk_registry', \Wbcom\Credits\Registry::instance() );

		// Boot every registered plugin.
		\Wbcom\Credits\Registry::instance()->boot_all();
	}

	// Late-include fallback: if `after_setup_theme` already fired before we
	// got here, run registration + initialization synchronously so the SDK
	// is usable on this same request.
	if ( did_action( 'after_setup_theme' ) && ! doing_action( 'after_setup_theme' ) && ! defined( 'WBCOM_CREDITS_SDK_VERSION' ) ) {
		wbcom_credits_sdk_register_1_2_0();
		\Wbcom\Credits\Versions::initialize_latest_version();
	}
}
