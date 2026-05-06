<?php
/**
 * Gateway_Registry — discover, register, and dispatch direct payment gateways.
 *
 * Mirrors the {@see \Wbcom\Credits\Adapters\AdapterRegistry} pattern so
 * the consuming plugin can find every available gateway via one call.
 * Third-party plugins can register additional gateways through the
 * `wbcom_credits_register_gateways` action — same shape as the adapter
 * registration hook, so authors only learn one pattern.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Per-slug registry of payment gateways.
 *
 * @since 1.2.0
 */
final class Gateway_Registry {

	/**
	 * Consuming plugin slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Gateways keyed by gateway id.
	 *
	 * @var array<string, GatewayInterface>
	 */
	private array $gateways = array();

	public function __construct( string $slug ) {
		$this->slug = $slug;
		$this->register_built_in_gateways();
	}

	/**
	 * Boot gateways and fire the extension hook so third parties can register.
	 */
	public function boot(): void {

		/**
		 * Fires when the gateway registry is ready for extensions.
		 *
		 * @since 1.2.0
		 *
		 * @param Gateway_Registry $registry
		 * @param string           $slug
		 */
		do_action( 'wbcom_credits_register_gateways', $this, $this->slug );
	}

	public function register( GatewayInterface $gateway ): void {
		$this->gateways[ $gateway->get_id() ] = $gateway;
	}

	public function get( string $id ): ?GatewayInterface {
		return $this->gateways[ $id ] ?? null;
	}

	/**
	 * @return array<string, GatewayInterface>
	 */
	public function get_all(): array {
		return $this->gateways;
	}

	/**
	 * @return array<string, GatewayInterface>
	 */
	public function get_available(): array {
		return array_filter(
			$this->gateways,
			static fn( GatewayInterface $g ): bool => $g->is_available()
		);
	}

	public function get_slug(): string {
		return $this->slug;
	}

	private function register_built_in_gateways(): void {
		$this->register( new Stripe() );
		$this->register( new PayPal() );
	}

	// -------------------------------------------------------------------------
	// Per-slug singleton plumbing — boot_all() in Registry uses this so the
	// REST controller and admin UI can resolve the right registry by slug.
	// -------------------------------------------------------------------------

	/**
	 * Per-slug instances cached for the request lifetime.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = array();

	/**
	 * Resolve (or create) the registry for a given slug.
	 */
	public static function for_slug( string $slug ): self {
		if ( ! isset( self::$instances[ $slug ] ) ) {
			self::$instances[ $slug ] = new self( $slug );
		}
		return self::$instances[ $slug ];
	}

	/**
	 * Reset cached instances. Test-only.
	 */
	public static function reset_for_tests(): void {
		self::$instances = array();
	}
}
