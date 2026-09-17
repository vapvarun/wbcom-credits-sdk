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
 * @version 1.7.1
 * @license GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

/*
 * ─── One copy wins, and only that copy loads ─────────────────────────────
 *
 * Several plugins on the same site each bundle their own copy of this SDK,
 * and PHP has exactly one `\Wbcom\Credits\Credits` per request. So the
 * question is never "can they coexist" — it is "which copy gets to be the
 * one", and the answer has to be the NEWEST, not the luckiest.
 *
 * Two earlier designs got that wrong, both by deciding at load time:
 *
 *   1. A boolean flag. The first bootstrap to run loaded its whole class
 *      set and every later one skipped. Fine while every copy was
 *      identical; the moment versions differed, an old copy could win and
 *      a newer consumer fataled on "Class X not found".
 *   2. Fill-in. Each bootstrap loaded only the classes not already in
 *      memory. That fixed MISSING classes but not OLD ones: the first copy
 *      still defined `Credits`, so a newer consumer calling a method added
 *      after that version got "Call to undefined method" instead — a white
 *      screen on every charge (support ticket 41719).
 *
 * Both fought over load order. This one does not load anything at include
 * time at all. Each copy only announces where it is and what version it is,
 * which costs nothing and leaves the decision open. The first class anyone
 * actually touches is resolved through a single autoloader that picks the
 * highest version announced so far and serves EVERY class from that one
 * directory. Plugin files all run before any hook fires, so by first use
 * every copy on the site has announced itself.
 *
 * Stale bundles stop mattering: a plugin three versions behind announces,
 * loses, and supplies nothing. Nobody has to keep every bundle in lockstep
 * to avoid a fatal — lockstep becomes a hygiene goal, not a safety
 * requirement.
 *
 * One honest limit: a copy from BEFORE this loader (1.6.0 and earlier)
 * still requires its own files at include time. If one of those runs first
 * it defines the classes and this autoloader is never consulted, so it
 * wins on load order the old way. Consumers keep a readiness check for
 * exactly that case — see CONSUMERS.md — and the exposure shrinks with
 * every plugin that ships this loader.
 */

// Announce this copy. No file is read and no class is defined here, so a
// higher version included later in the request can still win.
if ( ! isset( $GLOBALS['wbcom_credits_sdk_copies'] ) ) {
	$GLOBALS['wbcom_credits_sdk_copies'] = array();
}
$GLOBALS['wbcom_credits_sdk_copies'][ __DIR__ ] = '1.7.1';

if ( ! function_exists( 'wbcom_credits_sdk_class_map' ) ) {

	/**
	 * Class → file, relative to whichever copy wins.
	 *
	 * An explicit map rather than PSR-4 because several class names do not
	 * match their file names (WooCommerceAdapter lives in WooCommerce.php).
	 *
	 * @since 1.7.0
	 *
	 * @return array<string, string>
	 */
	function wbcom_credits_sdk_class_map(): array {
		return array(
			'Wbcom\\Credits\\Versions'                          => '/src/Versions.php',
			'Wbcom\\Credits\\Registry'                          => '/src/Registry.php',
			'Wbcom\\Credits\\Ledger'                            => '/src/Ledger.php',
			'Wbcom\\Credits\\Money'                             => '/src/Money.php',
			'Wbcom\\Credits\\Credits'                           => '/src/Credits.php',
			'Wbcom\\Credits\\Consumer'                          => '/src/Consumer.php',
			'Wbcom\\Credits\\REST'                              => '/src/REST.php',
			'Wbcom\\Credits\\Template'                          => '/src/Template.php',
			'Wbcom\\Credits\\Adapters\\AdapterInterface'        => '/src/Adapters/AdapterInterface.php',
			'Wbcom\\Credits\\Adapters\\AdapterRegistry'         => '/src/Adapters/AdapterRegistry.php',
			'Wbcom\\Credits\\Adapters\\WooCommerceAdapter'      => '/src/Adapters/WooCommerce.php',
			'Wbcom\\Credits\\Adapters\\WooSubscriptionsAdapter' => '/src/Adapters/WooSubscriptions.php',
			'Wbcom\\Credits\\Adapters\\WooMembershipsAdapter'   => '/src/Adapters/WooMemberships.php',
			'Wbcom\\Credits\\Adapters\\PMProAdapter'            => '/src/Adapters/PMPro.php',
			'Wbcom\\Credits\\Adapters\\MemberPressAdapter'      => '/src/Adapters/MemberPress.php',
			'Wbcom\\Credits\\Gateways\\GatewayInterface'        => '/src/Gateways/GatewayInterface.php',
			'Wbcom\\Credits\\Gateways\\Gateway_Event'           => '/src/Gateways/Gateway_Event.php',
			'Wbcom\\Credits\\Gateways\\Processed_Events'        => '/src/Gateways/Processed_Events.php',
			'Wbcom\\Credits\\Gateways\\Idempotency'             => '/src/Gateways/Idempotency.php',
			'Wbcom\\Credits\\Gateways\\Pending_Checkouts'       => '/src/Gateways/Pending_Checkouts.php',
			'Wbcom\\Credits\\Gateways\\Signature_Verifier'      => '/src/Gateways/Signature_Verifier.php',
			'Wbcom\\Credits\\Gateways\\Transaction_Log'         => '/src/Gateways/Transaction_Log.php',
			'Wbcom\\Credits\\Gateways\\Abstract_Gateway'        => '/src/Gateways/Abstract_Gateway.php',
			'Wbcom\\Credits\\Gateways\\Stripe'                  => '/src/Gateways/Stripe.php',
			'Wbcom\\Credits\\Gateways\\PayPal'                  => '/src/Gateways/PayPal.php',
			'Wbcom\\Credits\\Gateways\\Gateway_Registry'        => '/src/Gateways/Gateway_Registry.php',
			'Wbcom\\Credits\\Gateways\\Webhook_Controller'      => '/src/Gateways/Webhook_Controller.php',
			'Wbcom\\Credits\\Gateways\\Admin_Form_Renderer'     => '/src/Gateways/Admin_Form_Renderer.php',
			'Wbcom\\Credits\\Gateways\\Pricing'                 => '/src/Gateways/Pricing.php',
			'Wbcom\\Credits\\Gateways\\Pack_Admin_Renderer'     => '/src/Gateways/Pack_Admin_Renderer.php',
		);
	}

	/**
	 * Directory of the highest-versioned copy announced on this request.
	 *
	 * Resolved once, on the first class anyone asks for, and reused for
	 * every class after it — mixing two copies is the bug this loader
	 * exists to prevent, so the winner is picked once and never revisited.
	 *
	 * @since 1.7.0
	 *
	 * @return string Absolute directory path, or '' when nothing announced.
	 */
	function wbcom_credits_sdk_dir(): string {
		static $winner = null;

		if ( null !== $winner ) {
			return $winner;
		}

		$winner  = '';
		$highest = '';

		foreach ( (array) ( $GLOBALS['wbcom_credits_sdk_copies'] ?? array() ) as $dir => $version ) {
			// Ties keep the copy that announced first; there is nothing to
			// choose between two copies of the same version.
			if ( '' === $highest || version_compare( (string) $version, $highest, '>' ) ) {
				$highest = (string) $version;
				$winner  = (string) $dir;
			}
		}

		if ( '' !== $winner && ! defined( 'WBCOM_CREDITS_SDK_LOADED_FROM' ) ) {
			define( 'WBCOM_CREDITS_SDK_LOADED_FROM', $winner );
			define( 'WBCOM_CREDITS_SDK_LOADED_VERSION', $highest );
		}

		return $winner;
	}

	/**
	 * Load an SDK class from the winning copy.
	 *
	 * @since 1.7.0
	 *
	 * @param string $class Fully-qualified class name.
	 * @return void
	 */
	function wbcom_credits_sdk_autoload( string $class ): void {
		if ( 0 !== strpos( $class, 'Wbcom\\Credits\\' ) ) {
			return;
		}

		$map = wbcom_credits_sdk_class_map();

		if ( ! isset( $map[ $class ] ) ) {
			return;
		}

		$dir = wbcom_credits_sdk_dir();

		if ( '' === $dir ) {
			return;
		}

		$file = $dir . $map[ $class ];

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	spl_autoload_register( 'wbcom_credits_sdk_autoload' );
}

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
if ( ! function_exists( 'wbcom_credits_sdk_register_1_7_1' ) && function_exists( 'add_action' ) ) {

	add_action( 'after_setup_theme', array( '\\Wbcom\\Credits\\Versions', 'initialize_latest_version' ), 1, 0 );
	add_action( 'after_setup_theme', 'wbcom_credits_sdk_register_1_7_1', 0, 0 );

	/**
	 * Register this version with Versions::instance().
	 *
	 * @since 1.3.0
	 * @return void
	 */
	function wbcom_credits_sdk_register_1_7_1(): void {
		\Wbcom\Credits\Versions::instance()->register( '1.7.1', 'wbcom_credits_sdk_initialize_1_7_1' );
	}

	/**
	 * Initialize this version (called only if Versions picked it as latest).
	 *
	 * @since 1.3.0
	 * @return void
	 */
	function wbcom_credits_sdk_initialize_1_7_1(): void {
		if ( ! defined( 'WBCOM_CREDITS_SDK_VERSION' ) ) {
			define( 'WBCOM_CREDITS_SDK_VERSION', '1.7.1' );
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
		wbcom_credits_sdk_register_1_7_1();
		\Wbcom\Credits\Versions::initialize_latest_version();
	}
}
