<?php
/**
 * Adapter interface — contract for all credit-source adapters.
 *
 * @package Wbcom\Credits\Adapters
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Every credit-source adapter (WooCommerce, PMPro, MemberPress, etc.) must
 * implement this interface so the SDK can discover, display, and wire it.
 *
 * @since 1.0.0
 */
interface AdapterInterface {

	/**
	 * Unique machine-readable identifier, e.g. 'woocommerce'.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable label shown in admin settings, e.g. 'WooCommerce'.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Whether the underlying plugin/extension is installed and active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Register WordPress hooks that listen for payment/membership events.
	 *
	 * Receives the consuming plugin's slug so the adapter knows which credit
	 * pool to top-up and which option key to read mappings from.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Consuming plugin slug, e.g. 'wp-career-board-pro'.
	 * @return void
	 */
	public function register_hooks( string $slug ): void;

	/**
	 * Return an array of purchasable items that can be mapped to credit amounts.
	 *
	 * Each item is an associative array with at least 'id' and 'label' keys.
	 * For example, WooCommerce returns products; PMPro returns membership levels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{ id: int|string, label: string }>
	 */
	public function get_mappable_items(): array;
}
