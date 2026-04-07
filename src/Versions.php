<?php
/**
 * Multi-version support — only the highest bundled SDK version initializes.
 *
 * @package Wbcom\Credits
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks all bundled SDK versions and initializes only the latest.
 *
 * @since 1.0.0
 */
final class Versions {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered versions and their initialization callbacks.
	 *
	 * @var array<string, callable>
	 */
	private array $versions = array();

	/**
	 * Register a version with its initialization callback.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $version  Semantic version string.
	 * @param callable $callback Initialization function.
	 * @return bool True if registered, false if already exists.
	 */
	public function register( string $version, callable $callback ): bool {
		if ( isset( $this->versions[ $version ] ) ) {
			return false;
		}

		$this->versions[ $version ] = $callback;
		return true;
	}

	/**
	 * Get the latest registered version string.
	 *
	 * @since 1.0.0
	 * @return string|false Version string or false if none registered.
	 */
	public function latest_version(): string|false {
		$keys = array_keys( $this->versions );
		if ( empty( $keys ) ) {
			return false;
		}
		uasort( $keys, 'version_compare' );
		return end( $keys );
	}

	/**
	 * Get the initialization callback for the latest version.
	 *
	 * @since 1.0.0
	 * @return callable
	 */
	public function latest_version_callback(): callable {
		$latest = $this->latest_version();
		if ( false === $latest || ! isset( $this->versions[ $latest ] ) ) {
			return '__return_null';
		}
		return $this->versions[ $latest ];
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

	/**
	 * Initialize the latest registered version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function initialize_latest_version(): void {
		$self = self::instance();
		call_user_func( $self->latest_version_callback() );
	}
}
