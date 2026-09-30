<?php
/**
 * Template loader — locate and render reusable SDK templates.
 *
 * Renders the templates the SDK ships: frontend/receipt.php and
 * admin/gateways-section.php (1.9.x). There are no admin Credits tab,
 * Transactions page or dashboard templates yet; consumers render those in
 * their own admin with Admin_Form_Renderer, Pack_Admin_Renderer and
 * Coupons. Themes can override a template by dropping a file in their theme.
 *
 * Lookup precedence:
 *   1. {theme}/wbcom-credits/{plugin_slug}/{name}.php  — plugin-specific override
 *   2. {theme}/wbcom-credits/{name}.php                — global theme override
 *   3. {sdk}/templates/{name}.php                      — SDK default
 *
 * @package Wbcom\Credits
 * @since   1.1.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable template loader for consuming plugins.
 *
 * @since 1.1.0
 */
final class Template {

	/**
	 * Locate and render a template, passing args into its scope.
	 *
	 * @since 1.1.0
	 *
	 * @param string               $name        Template name relative to templates/ (e.g. 'admin/credits-tab').
	 * @param array<string, mixed> $args        Variables exposed to the template as $args.
	 * @param string               $plugin_slug Consuming plugin slug for plugin-specific theme overrides.
	 * @return void
	 *
	 * @deprecated 1.10.0 The SDK ships no templates from 2.0.0; consumers render their own. Removed in 2.0.0. See docs/HEADLESS-PLAN.md.
	 */
	public static function get( string $name, array $args = array(), string $plugin_slug = '' ): void {
		$located = self::locate( $name, $plugin_slug );

		if ( '' === $located ) {
			return;
		}

		/**
		 * Filter args passed to an SDK template immediately before rendering.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, mixed> $args        Template args.
		 * @param string               $name        Normalized template name.
		 * @param string               $plugin_slug Consuming plugin slug.
		 * @param string               $located     Absolute path to the template file.
		 */
		$args = apply_filters( 'wbcom_credits_template_args', $args, $name, $plugin_slug, $located );

		include $located;
	}

	/**
	 * Resolve a template name to an absolute file path.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name        Template name relative to templates/ (without or with `.php`).
	 * @param string $plugin_slug Consuming plugin slug for plugin-specific theme overrides.
	 * @return string Absolute path, or empty string if the template could not be found.
	 */
	public static function locate( string $name, string $plugin_slug = '' ): string {
		$normalized = self::normalize_name( $name );
		if ( '' === $normalized ) {
			return '';
		}

		$paths = array();

		if ( '' !== $plugin_slug ) {
			$paths[] = 'wbcom-credits/' . $plugin_slug . '/' . $normalized;
		}
		$paths[] = 'wbcom-credits/' . $normalized;

		$located = function_exists( 'locate_template' ) ? locate_template( $paths ) : '';

		if ( '' === $located ) {
			$default = WBCOM_CREDITS_SDK_PATH . '/templates/' . $normalized;
			if ( is_readable( $default ) ) {
				$located = $default;
			}
		}

		/**
		 * Filter the resolved template path.
		 *
		 * Allows consuming plugins or site code to swap in a custom file without
		 * creating a theme override — useful for conditional or dynamic templates.
		 *
		 * @since 1.1.0
		 *
		 * @param string $located     Absolute path, or empty string if not found.
		 * @param string $normalized  Normalized template name including `.php`.
		 * @param string $plugin_slug Consuming plugin slug.
		 */
		return (string) apply_filters( 'wbcom_credits_template_path', $located, $normalized, $plugin_slug );
	}

	/**
	 * Normalize a template name: strip leading slash, block traversal, ensure `.php` suffix.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name Raw template name.
	 * @return string Normalized name, or empty string if invalid.
	 */
	private static function normalize_name( string $name ): string {
		$name = ltrim( $name, '/\\' );

		if ( '' === $name || false !== strpos( $name, '..' ) ) {
			return '';
		}

		if ( '.php' !== substr( $name, -4 ) ) {
			$name .= '.php';
		}

		return $name;
	}
}
