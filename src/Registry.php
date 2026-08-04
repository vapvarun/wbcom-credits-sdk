<?php
/**
 * Plugin registry — consuming plugins register their credit configuration here.
 *
 * @package Wbcom\Credits
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Stores credit configurations for all consuming plugins.
 *
 * @since 1.0.0
 */
final class Registry {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered plugin configurations keyed by slug.
	 *
	 * @var array<string, array>
	 */
	private array $plugins = array();

	/**
	 * Register a consuming plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config {
	 *     Plugin credit configuration.
	 *
	 *     @type string          $slug       Unique plugin identifier (required).
	 *     @type string          $prefix     DB table prefix, e.g. 'wcb' (required).
	 *     @type string          $version    Plugin version.
	 *     @type string          $file       Main plugin file path.
	 *     @type string          $user_type  Label for credit holders, e.g. 'employer'.
	 *     @type array           $consumers  Array of consumer definitions (what costs credits).
	 *     @type string|callable $return_url Where buyers land after a direct-pay checkout when the
	 *                                       request does not name a page — the plugin's own wallet
	 *                                       or dashboard screen. A callable is invoked at checkout
	 *                                       time so page IDs resolve live. Without it, the gateway
	 *                                       falls back to its settings URL, then the site home —
	 *                                       which is never where a buyer expects to land.
	 *     @type array           $settings   Optional overrides: low_threshold, purchase_url, admin_settings_hook.
	 * }
	 * @return void
	 */
	public function register( array $config ): void {
		if ( empty( $config['slug'] ) ) {
			_doing_it_wrong( __METHOD__, 'The "slug" key is required.', '1.0.0' );
			return;
		}

		if ( empty( $config['prefix'] ) ) {
			_doing_it_wrong( __METHOD__, 'The "prefix" key is required.', '1.0.0' );
			return;
		}

		$slug = sanitize_key( $config['slug'] );

		$this->plugins[ $slug ] = wp_parse_args(
			$config,
			array(
				'slug'      => $slug,
				'prefix'    => '',
				'version'   => '1.0.0',
				'file'      => '',
				'user_type' => 'user',
				'consumers' => array(),
				'settings'  => array(
					'low_threshold'      => 5,
					'purchase_url'       => '',
					'admin_settings_hook' => '',
				),
			)
		);
	}

	/**
	 * Get configuration for a registered plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Plugin slug.
	 * @return array|null Configuration array or null if not registered.
	 */
	public function get( string $slug ): ?array {
		return $this->plugins[ $slug ] ?? null;
	}

	/**
	 * Resolve the consumer-registered checkout return URL for a slug.
	 *
	 * The consuming plugin knows where its buyers top up — its own wallet
	 * or dashboard screen — so it registers that page and every checkout
	 * without an explicit request return_url lands there instead of the
	 * site home.
	 *
	 * @since 1.6.0
	 *
	 * @param string $slug Plugin slug.
	 * @return string Absolute URL, or '' when none is registered.
	 */
	public function return_url_for( string $slug ): string {
		$registered = $this->plugins[ $slug ]['return_url'] ?? '';
		if ( is_callable( $registered ) ) {
			$registered = call_user_func( $registered );
		}

		return is_string( $registered ) ? $registered : '';
	}

	/**
	 * Get all registered plugin slugs.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public function get_slugs(): array {
		return array_keys( $this->plugins );
	}

	/**
	 * Get all registered plugin configurations.
	 *
	 * @since 1.0.0
	 * @return array<string, array>
	 */
	public function get_all(): array {
		return $this->plugins;
	}

	/**
	 * Boot all registered plugins — wire consumers, adapters, REST, admin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function boot_all(): void {
		foreach ( $this->plugins as $slug => $config ) {
			// Ensure the per-consumer schema is current (creates/upgrades
			// the ledger, gateway log, and processed-events tables). Guarded
			// by a stored DB-version option so the SHOW TABLES probes only
			// run when the schema version actually changes.
			self::maybe_upgrade_schema( (string) $config['prefix'] );

			// Wire consumer hooks (hold/deduct/refund lifecycle).
			foreach ( $config['consumers'] as $consumer_config ) {
				$consumer = new Consumer( $slug, $config['prefix'], $consumer_config );
				$consumer->register_hooks();
			}

			// Initialize adapter registry for this plugin.
			$adapter_registry = new Adapters\AdapterRegistry( $slug, $config['prefix'] );
			if ( did_action( 'plugins_loaded' ) ) {
				// plugins_loaded already fired — boot adapters immediately.
				$adapter_registry->boot();
			} else {
				add_action( 'plugins_loaded', array( $adapter_registry, 'boot' ), 20 );
			}

			// Initialize the direct-payment gateway registry.
			$gateway_registry = Gateways\Gateway_Registry::for_slug( $slug );
			if ( did_action( 'plugins_loaded' ) ) {
				$gateway_registry->boot();
			} else {
				add_action( 'plugins_loaded', array( $gateway_registry, 'boot' ), 20 );
			}

			// Register REST API endpoints (existing balance/topup + gateway routes).
			add_action( 'rest_api_init', static function () use ( $slug, $config ): void {
				( new REST( $slug, $config['prefix'], $config['user_type'] ) )->register_routes();
				( new Gateways\Webhook_Controller( $slug ) )->register_routes();
			} );
		}

		self::register_checkout_script();
	}

	/**
	 * Attach the reusable browser-side checkout helper to
	 * `wp_enqueue_scripts` once per boot_all() call, regardless of how many
	 * consuming plugins are registered.
	 *
	 * Consuming plugins call wp_enqueue_script() themselves where they
	 * render a buy-credits UI, then call
	 * window.wbcomCreditsCheckout({ slug, gateway, pack_id|credits }). The
	 * handle is global (shared across every consumer on the site, not
	 * slug-specific), so this is wired once here — outside boot_all()'s
	 * per-consumer foreach — instead of attaching N identical closures to
	 * the same hook when N plugins register. The wp_script_is() guard
	 * inside the closure is kept as a second, independent safeguard (e.g.
	 * against another `wbcom-credits-checkout` registration elsewhere).
	 *
	 * @since 1.3.2
	 * @return void
	 */
	private static function register_checkout_script(): void {
		add_action( 'wp_enqueue_scripts', static function (): void {
			$handle = 'wbcom-credits-checkout';
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				wp_register_script(
					$handle,
					plugins_url( 'assets/js/checkout.js', WBCOM_CREDITS_SDK_PATH . '/wbcom-credits-sdk.php' ),
					array(),
					WBCOM_CREDITS_SDK_VERSION,
					true
				);
				wp_localize_script(
					$handle,
					'wbcomCreditsCfg',
					array(
						'restRoot' => esc_url_raw( rest_url( 'wbcom-credits/v1/' ) ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
					)
				);
			}
		} );
	}

	/**
	 * Current SDK schema version.
	 *
	 * Bump this whenever a table is added or a column/key changes so
	 * {@see maybe_upgrade_schema()} re-runs the idempotent create/upgrade
	 * pass on the next request after a consumer ships the new SDK.
	 *
	 * History:
	 *  - 1: ledger + gateway transaction-log tables (SDK 1.2.0).
	 *  - 2: added {prefix}_credit_processed_events with UNIQUE(slug,gateway,
	 *       event_id) for atomic webhook dedupe. This version ALSO intended to
	 *       add the gateway-log `payment_intent` column + idx_intent index, but
	 *       a bug made Transaction_Log::maybe_create_table() early-return on
	 *       existing tables, so the column was added on FRESH installs only.
	 *       Sites that booted that build are stuck at version 2 WITHOUT the
	 *       column — the gate believes the work is done.
	 *  - 3: re-runs the now-fixed idempotent backfill so installs corrupted by
	 *       the v2 bug (version=2, no payment_intent column) get the column +
	 *       idx_intent index added. The backfill is a no-op where they already
	 *       exist (fresh installs), so bumping the version is safe for everyone.
	 *
	 * @since 1.3.1
	 * @var int
	 */
	private const SCHEMA_VERSION = 3;

	/**
	 * Create or upgrade the per-consumer schema, guarded by a stored
	 * DB-version option so the work runs only when the schema changes.
	 *
	 * All create steps are themselves idempotent (SHOW TABLES guard +
	 * dbDelta / CREATE-if-missing), so this is safe to run repeatedly; the
	 * version gate just spares the probes on the steady-state hot path.
	 *
	 * @since 1.3.1
	 *
	 * @param string $prefix Consumer DB prefix.
	 * @return void
	 */
	private static function maybe_upgrade_schema( string $prefix ): void {
		$prefix = sanitize_key( $prefix );
		if ( '' === $prefix ) {
			return;
		}

		$option_key = 'wbcom_credits_db_version_' . $prefix;
		$installed  = (int) get_option( $option_key, 0 );
		if ( $installed >= self::SCHEMA_VERSION ) {
			return;
		}

		// Append-only ledger (canonical balance source).
		Ledger::maybe_create_table( $prefix );

		// Per-gateway transaction log for direct payments.
		Gateways\Transaction_Log::maybe_create_table( $prefix );

		// Atomic webhook dedupe store (UNIQUE constraint backed).
		Gateways\Processed_Events::maybe_create_table( $prefix );

		update_option( $option_key, self::SCHEMA_VERSION, false );
	}

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
