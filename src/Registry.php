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
			// Create the ledger table if needed.
			Ledger::maybe_create_table( $config['prefix'] );

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

			// Register REST API endpoints.
			add_action( 'rest_api_init', static function () use ( $slug, $config ): void {
				$rest = new REST( $slug, $config['prefix'], $config['user_type'] );
				$rest->register_routes();
			} );
		}
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
