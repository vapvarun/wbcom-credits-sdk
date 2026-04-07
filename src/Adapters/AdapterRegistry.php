<?php
/**
 * Adapter registry — discovers, registers, and boots all credit-source adapters.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry for credit-source adapters.
 *
 * Instantiated per consuming plugin (each gets its own slug + prefix).
 * Third-party plugins can register additional adapters via the
 * `wbcom_credits_register_adapters` action.
 *
 * @since 1.0.0
 */
final class AdapterRegistry {

	/**
	 * Consuming plugin slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Consuming plugin DB table prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Registered adapters keyed by adapter ID.
	 *
	 * @var array<string, AdapterInterface>
	 */
	private array $adapters = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug   Consuming plugin slug.
	 * @param string $prefix Consuming plugin DB table prefix.
	 */
	public function __construct( string $slug, string $prefix ) {
		$this->slug   = $slug;
		$this->prefix = $prefix;

		$this->register_built_in_adapters();
	}

	/**
	 * Boot the adapter system.
	 *
	 * Fires the extension hook so third-party adapters can register,
	 * then calls `register_hooks()` on every available adapter.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot(): void {

		/**
		 * Fires when the adapter registry is ready for extensions.
		 *
		 * Third-party plugins can call `$registry->register()` to add
		 * custom credit-source adapters.
		 *
		 * @since 1.0.0
		 *
		 * @param AdapterRegistry $registry The adapter registry instance.
		 * @param string          $slug     Consuming plugin slug.
		 */
		do_action( 'wbcom_credits_register_adapters', $this, $this->slug );

		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->is_available() ) {
				$adapter->register_hooks( $this->slug );
			}
		}
	}

	/**
	 * Register an adapter instance.
	 *
	 * @since 1.0.0
	 *
	 * @param AdapterInterface $adapter Adapter to register.
	 * @return void
	 */
	public function register( AdapterInterface $adapter ): void {
		$this->adapters[ $adapter->get_id() ] = $adapter;
	}

	/**
	 * Get a specific adapter by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Adapter identifier.
	 * @return AdapterInterface|null
	 */
	public function get( string $id ): ?AdapterInterface {
		return $this->adapters[ $id ] ?? null;
	}

	/**
	 * Get all registered adapters.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, AdapterInterface>
	 */
	public function get_all(): array {
		return $this->adapters;
	}

	/**
	 * Get only the adapters whose underlying plugin is active.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, AdapterInterface>
	 */
	public function get_available(): array {
		return array_filter(
			$this->adapters,
			static fn( AdapterInterface $a ): bool => $a->is_available()
		);
	}

	/**
	 * Look up how many credits a purchasable item maps to.
	 *
	 * Reads the option `{slug}_credit_mappings` which stores an array of
	 * adapter_id => [ item_id => credit_amount ] mappings.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $adapter_id Adapter identifier, e.g. 'woocommerce'.
	 * @param int|string $item_id    Purchasable item ID (product, level, etc.).
	 * @return int Credit amount (0 if no mapping found).
	 */
	public function lookup_credits( string $adapter_id, int|string $item_id ): int {
		$option_key = $this->slug . '_credit_mappings';
		$mappings   = get_option( $option_key, array() );

		if ( ! is_array( $mappings ) ) {
			return 0;
		}

		$adapter_map = $mappings[ $adapter_id ] ?? array();

		return (int) ( $adapter_map[ $item_id ] ?? 0 );
	}

	/**
	 * Get the consuming plugin slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Get the consuming plugin DB prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_prefix(): string {
		return $this->prefix;
	}

	/**
	 * Register all built-in adapters.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function register_built_in_adapters(): void {
		$this->register( new WooCommerceAdapter() );
		$this->register( new WooSubscriptionsAdapter() );
		$this->register( new WooMembershipsAdapter() );
		$this->register( new PMProAdapter() );
		$this->register( new MemberPressAdapter() );
	}
}
