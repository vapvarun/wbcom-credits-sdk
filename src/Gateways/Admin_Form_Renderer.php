<?php
/**
 * Admin form renderer for direct payment gateway settings.
 *
 * Drop-in helper any consuming plugin can call to render + save the gateway
 * settings UI without rebuilding the same form per plugin. Iterates every
 * gateway registered against the plugin's slug, pulls each one's
 * `get_settings_fields()` schema, renders a `<form>` with sections, and
 * displays the webhook URL prominently for paste-into-provider-dashboard.
 *
 * Lookup precedence for the section template (overridable):
 *   1. {theme}/wbcom-credits/{slug}/admin/gateways-section.php
 *   2. {theme}/wbcom-credits/admin/gateways-section.php
 *   3. SDK default at templates/admin/gateways-section.php
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

use Wbcom\Credits\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Settings UI helper for direct payment gateways.
 *
 * @since 1.2.0
 */
final class Admin_Form_Renderer {

	/**
	 * Render the gateway settings UI for a consuming plugin's slug.
	 *
	 * Two render modes — pick the one matching your settings page:
	 *
	 * 1. Standalone form (default) — emits a `<form method="post">` with its
	 *    own nonce + Save button. Pair with `handle_save( $slug )` on
	 *    `admin_init`. Use when your plugin has a dedicated admin page that
	 *    doesn't already wrap content in a form.
	 *
	 * 2. Inline / Settings-API mode (`['as_form' => false]`) — emits ONLY
	 *    the per-gateway sections (no form wrapper, no nonce, no submit).
	 *    Pair with `register_setting( $option_group, 'wbcom_credits_gateway_settings_' . $slug,
	 *    [ 'sanitize_callback' => [ Admin_Form_Renderer::class, 'sanitize_for_settings_api' ] ] )`.
	 *    Use when integrating into a parent `<form action="options.php">`
	 *    rendered by another plugin's Settings API tab.
	 *
	 * @since 1.2.0
	 *
	 * @param string                       $slug Consuming plugin slug.
	 * @param array{as_form?: bool}|array  $args Optional. Render args. Defaults to standalone form mode.
	 * @return void
	 */
	public static function render( string $slug, array $args = array() ): void {
		$as_form = ! isset( $args['as_form'] ) || (bool) $args['as_form'];

		$registry = Gateway_Registry::for_slug( $slug );
		$gateways = $registry->get_all();

		if ( empty( $gateways ) ) {
			echo '<p>' . esc_html__( 'No payment gateways registered for this plugin.', 'wbcom-credits-sdk' ) . '</p>';
			return;
		}

		$saved = self::get_saved_settings( $slug );

		// Prepare gateway descriptors for the template.
		$gateway_views = array();
		foreach ( $gateways as $gateway ) {
			$gateway_id = $gateway->get_id();
			$values     = (array) ( $saved[ $gateway_id ] ?? array() );

			$gateway_views[] = array(
				'id'              => $gateway_id,
				'label'           => $gateway->get_label(),
				'available'       => $gateway->is_available(),
				'fields'          => $gateway->get_settings_fields(),
				'values'          => $values,
				'webhook_url'     => self::webhook_url( $slug, $gateway_id ),
				'success_default' => self::default_success_url(),
				'cancel_default'  => self::default_cancel_url(),
			);
		}

		if ( $as_form ) {
			?>
			<form method="post" class="wbcom-credits-gateways-form" action="">
				<?php wp_nonce_field( 'wbcom_credits_save_gateways_' . $slug, 'wbcom_credits_gateways_nonce' ); ?>
				<input type="hidden" name="wbcom_credits_save_gateways" value="<?php echo esc_attr( $slug ); ?>" />
			<?php
		}

		Template::get(
			'admin/gateways-section',
			array(
				'slug'     => $slug,
				'gateways' => $gateway_views,
			),
			$slug
		);

		if ( $as_form ) {
			?>
				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save Gateway Settings', 'wbcom-credits-sdk' ); ?>
					</button>
				</p>
			</form>
			<?php
		}
	}

	/**
	 * Sanitize callback compatible with `register_setting()`.
	 *
	 * Hand this to `register_setting()` when integrating in inline /
	 * Settings-API mode. The callback walks every registered gateway's
	 * field schema, applies type-aware sanitization, and preserves
	 * already-saved password fields when the input is blank.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Raw POSTed value (typically array keyed by gateway id).
	 * @return array Sanitized settings array ready to persist.
	 */
	public static function sanitize_for_settings_api( $input ): array {
		// Resolve the slug from the option name in the current request.
		$slug = self::resolve_slug_from_option_name();
		if ( '' === $slug ) {
			return is_array( $input ) ? $input : array();
		}

		$registry = Gateway_Registry::for_slug( $slug );
		$gateways = $registry->get_all();
		$existing = self::get_saved_settings( $slug );
		$updated  = $existing;
		$input    = is_array( $input ) ? $input : array();

		foreach ( $gateways as $gateway ) {
			$gateway_id = $gateway->get_id();
			$old_values = (array) ( $existing[ $gateway_id ] ?? array() );

			// register_setting hands us the merged $_POST value matching the option key.
			// Inline-mode fields use name="gateway_<id>[<key>]" so the option's POST shape
			// is { gateway_<id> => { key => val, ... } } when fetched via the option name.
			// But register_setting() reads $_POST[<option_name>] which is keyed differently
			// — we read the raw POST shape directly to keep markup parallel.
			$posted = isset( $_POST[ 'gateway_' . $gateway_id ] ) && is_array( $_POST[ 'gateway_' . $gateway_id ] )
				? wp_unslash( $_POST[ 'gateway_' . $gateway_id ] )
				: array();

			$new_values = array();
			foreach ( $gateway->get_settings_fields() as $field ) {
				$key  = (string) ( $field['key'] ?? '' );
				$type = (string) ( $field['type'] ?? 'text' );
				if ( '' === $key ) {
					continue;
				}
				$raw                = $posted[ $key ] ?? null;
				$new_values[ $key ] = self::sanitize_field( $type, $raw, $old_values[ $key ] ?? '' );
			}

			$updated[ $gateway_id ] = $new_values;
		}

		/**
		 * Fires after gateway settings are sanitized via the Settings API path.
		 *
		 * @since 1.2.0
		 *
		 * @param string               $slug    Consuming plugin slug.
		 * @param array<string, array> $updated Sanitized settings keyed by gateway id.
		 */
		do_action( 'wbcom_credits_gateway_settings_saved', $slug, $updated );

		return $updated;
	}

	/**
	 * Determine which consuming plugin slug `register_setting` is currently saving.
	 *
	 * Inspects `$_POST['option_page']` against the option-name pattern
	 * `wbcom_credits_gateway_settings_{slug}`.
	 *
	 * @since 1.2.0
	 *
	 * @return string Slug or empty string when not resolvable.
	 */
	private static function resolve_slug_from_option_name(): string {
		// register_setting passes the new value to the sanitize callback at the
		// time options.php is processing the option_page. The current option
		// being saved is identified by the action target — but the sanitize
		// callback isn't told which option triggered it. Best signal: scan
		// $_POST keys for our option_name pattern.
		foreach ( (array) $_POST as $key => $_value ) {
			if ( is_string( $key ) && 0 === strpos( $key, 'wbcom_credits_gateway_settings_' ) ) {
				return (string) substr( $key, strlen( 'wbcom_credits_gateway_settings_' ) );
			}
		}
		return '';
	}

	/**
	 * Process a POSTed gateway settings save.
	 *
	 * Verifies nonce + capability, sanitizes per-field-schema, preserves
	 * already-saved password fields when the input is blank, and writes to
	 * `wbcom_credits_gateway_settings_{slug}`.
	 *
	 * Hook this on `admin_init` (or your equivalent admin save hook) wrapped
	 * in a check for `$_POST['wbcom_credits_save_gateways']`.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Consuming plugin slug.
	 * @return bool True on save, false on early-bail (auth/nonce failure or wrong slug).
	 */
	public static function handle_save( string $slug ): bool {
		// Wrong slug — POST is for a different plugin's form.
		if ( ! isset( $_POST['wbcom_credits_save_gateways'] ) || $slug !== sanitize_key( wp_unslash( $_POST['wbcom_credits_save_gateways'] ) ) ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$nonce = isset( $_POST['wbcom_credits_gateways_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wbcom_credits_gateways_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wbcom_credits_save_gateways_' . $slug ) ) {
			add_settings_error(
				'wbcom_credits_gateways',
				'invalid_nonce',
				__( 'Security check failed. Please reload and try again.', 'wbcom-credits-sdk' )
			);
			return false;
		}

		$registry = Gateway_Registry::for_slug( $slug );
		$gateways = $registry->get_all();
		$existing = self::get_saved_settings( $slug );
		$updated  = $existing;

		foreach ( $gateways as $gateway ) {
			$gateway_id  = $gateway->get_id();
			$old_values  = (array) ( $existing[ $gateway_id ] ?? array() );
			$posted      = isset( $_POST[ 'gateway_' . $gateway_id ] ) && is_array( $_POST[ 'gateway_' . $gateway_id ] )
				? wp_unslash( $_POST[ 'gateway_' . $gateway_id ] )
				: array();
			$new_values  = array();

			foreach ( $gateway->get_settings_fields() as $field ) {
				$key  = (string) ( $field['key'] ?? '' );
				$type = (string) ( $field['type'] ?? 'text' );
				if ( '' === $key ) {
					continue;
				}

				$raw = $posted[ $key ] ?? null;
				$new_values[ $key ] = self::sanitize_field( $type, $raw, $old_values[ $key ] ?? '' );
			}

			$updated[ $gateway_id ] = $new_values;
		}

		update_option( 'wbcom_credits_gateway_settings_' . $slug, $updated );

		add_settings_error(
			'wbcom_credits_gateways',
			'settings_saved',
			__( 'Gateway settings saved.', 'wbcom-credits-sdk' ),
			'success'
		);

		/**
		 * Fires after gateway settings are saved.
		 *
		 * @since 1.2.0
		 *
		 * @param string               $slug    Consuming plugin slug.
		 * @param array<string, array> $updated Saved settings keyed by gateway id.
		 */
		do_action( 'wbcom_credits_gateway_settings_saved', $slug, $updated );

		return true;
	}

	/**
	 * Resolve the webhook URL for a slug + gateway id.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug       Consuming plugin slug.
	 * @param string $gateway_id Gateway id (e.g. 'stripe', 'paypal').
	 * @return string Absolute webhook URL operators paste into the provider dashboard.
	 */
	public static function webhook_url( string $slug, string $gateway_id ): string {
		return rest_url( sprintf( 'wbcom-credits/v1/%s/webhook/%s', $slug, $gateway_id ) );
	}

	/**
	 * Get saved settings array for a slug, with safe defaults.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Consuming plugin slug.
	 * @return array<string, array<string, mixed>> Settings keyed by gateway id.
	 */
	public static function get_saved_settings( string $slug ): array {
		$value = get_option( 'wbcom_credits_gateway_settings_' . $slug, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Sanitize a single field value per its schema type.
	 *
	 * Password / secret fields preserve the prior value when the input is
	 * blank (UX: user re-saves form without re-typing every secret).
	 *
	 * @since 1.2.0
	 *
	 * @param string $type     Schema type from get_settings_fields().
	 * @param mixed  $raw      Raw POSTed value.
	 * @param mixed  $existing Previously-saved value (used as fallback for blank password fields).
	 * @return mixed Sanitized value.
	 */
	private static function sanitize_field( string $type, $raw, $existing ) {
		switch ( $type ) {
			case 'bool':
				return ! empty( $raw );

			case 'password':
				$value = is_string( $raw ) ? trim( $raw ) : '';
				// Blank input → preserve existing (so users don't have to re-paste keys).
				return '' === $value ? (string) $existing : $value;

			case 'url':
				return is_string( $raw ) ? esc_url_raw( $raw ) : '';

			case 'select':
				return is_string( $raw ) ? sanitize_key( $raw ) : '';

			case 'text':
			default:
				return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
		}
	}

	/**
	 * Default success URL — a polite landing the user lands on after paying.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function default_success_url(): string {
		return add_query_arg( 'wbcom_credits', 'success', home_url( '/' ) );
	}

	/**
	 * Default cancel URL — where the user lands if they abandon checkout.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function default_cancel_url(): string {
		return add_query_arg( 'wbcom_credits', 'cancel', home_url( '/' ) );
	}
}
