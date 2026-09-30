<?php
/**
 * Gateway settings as data: read, describe and save, with no markup.
 *
 * The consumer owns the form. It draws the gateway cards, the labels in its
 * own text domain, the nonce and capability check, and the saved/failed
 * notice. This class gives it the field schema, the saved values and one
 * save call. See docs/HEADLESS-PLAN.md.
 *
 * @package Wbcom\Credits
 * @since   1.10.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Headless gateway settings.
 *
 * @since 1.10.0
 */
final class Gateway_Settings {

	/**
	 * Option that stores every gateway's settings for a slug. A frozen
	 * contract: consumers read and write it directly.
	 *
	 * @since 1.10.0
	 * @param string $slug Consuming plugin slug.
	 * @return string
	 */
	public static function option_name( string $slug ): string {
		return 'wbcom_credits_gateway_settings_' . $slug;
	}

	/**
	 * One descriptor per registered gateway, for the consumer to render.
	 *
	 * Password values are never returned; `saved` says which of them hold a
	 * value so the form can show "saved" without echoing the secret.
	 *
	 * @since 1.10.0
	 * @param string $slug Consuming plugin slug.
	 * @return list<array{id: string, name: string, available: bool, fields: list<array<string, mixed>>, values: array<string, mixed>, saved: array<string, bool>, webhook_url: string}>
	 */
	public static function views( string $slug ): array {
		$saved = self::saved( $slug );
		$out   = array();

		foreach ( Gateway_Registry::for_slug( $slug )->get_all() as $gateway ) {
			$id     = $gateway->get_id();
			$stored = (array) ( $saved[ $id ] ?? array() );
			$fields = self::fields( $gateway );
			$values = array();
			$has    = array();

			foreach ( $fields as $field ) {
				$key            = $field['key'];
				$value          = $stored[ $key ] ?? '';
				$has[ $key ]    = '' !== $value && false !== $value;
				$values[ $key ] = 'password' === $field['type'] ? '' : $value;
			}

			$out[] = array(
				'id'          => $id,
				'name'        => $gateway->get_label(),
				'available'   => $gateway->is_available(),
				'fields'      => $fields,
				'values'      => $values,
				'saved'       => $has,
				'webhook_url' => self::webhook_url( $slug, $id ),
			);
		}

		return $out;
	}

	/**
	 * A gateway's settings fields without display text: key, type,
	 * required and, for a select, the option keys.
	 *
	 * @since 1.10.0
	 * @param GatewayInterface $gateway Gateway.
	 * @return list<array{key: string, type: string, required: bool, options: list<string>}>
	 */
	public static function fields( GatewayInterface $gateway ): array {
		$out = array();
		foreach ( $gateway->get_settings_fields() as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$out[] = array(
				'key'      => $key,
				'type'     => (string) ( $field['type'] ?? 'text' ),
				'required' => ! empty( $field['required'] ),
				'options'  => array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Sanitize and store posted settings for every gateway of a slug.
	 *
	 * The caller checks the capability and nonce first; this only handles
	 * data. A blank password keeps the stored secret, so re-saving the form
	 * never wipes a key; a select keeps only one of its declared options.
	 * Gateways missing from `$input` keep their values.
	 *
	 * @since 1.10.0
	 * @param string                              $slug  Consuming plugin slug.
	 * @param array<string, array<string, mixed>> $input Raw values keyed by gateway id, then field key.
	 * @return array<string, array<string, mixed>> The stored settings.
	 */
	public static function save( string $slug, array $input ): array {
		$existing = self::saved( $slug );
		$updated  = $existing;

		foreach ( Gateway_Registry::for_slug( $slug )->get_all() as $gateway ) {
			$id = $gateway->get_id();
			if ( ! isset( $input[ $id ] ) || ! is_array( $input[ $id ] ) ) {
				continue;
			}
			$old = (array) ( $existing[ $id ] ?? array() );
			$new = array();
			foreach ( self::fields( $gateway ) as $field ) {
				$key   = $field['key'];
				$value = self::sanitize_value( $field['type'], $input[ $id ][ $key ] ?? null, $old[ $key ] ?? '' );
				// A select only ever stores one of its own options.
				if ( 'select' === $field['type'] && array() !== $field['options'] && ! in_array( $value, $field['options'], true ) ) {
					$value = '';
				}
				$new[ $key ] = $value;
			}
			$updated[ $id ] = $new;
		}

		update_option( self::option_name( $slug ), $updated );

		/** This action is documented in src/Gateways/Admin_Form_Renderer.php */
		do_action( 'wbcom_credits_gateway_settings_saved', $slug, $updated );

		return $updated;
	}

	/**
	 * Stored settings for a slug, keyed by gateway id.
	 *
	 * @since 1.10.0
	 * @param string $slug Consuming plugin slug.
	 * @return array<string, array<string, mixed>>
	 */
	public static function saved( string $slug ): array {
		$value = get_option( self::option_name( $slug ), array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Webhook URL an operator pastes into the provider dashboard.
	 *
	 * @since 1.10.0
	 * @param string $slug       Consuming plugin slug.
	 * @param string $gateway_id Gateway id.
	 * @return string
	 */
	public static function webhook_url( string $slug, string $gateway_id ): string {
		return rest_url( sprintf( 'wbcom-credits/v1/%s/webhook/%s', $slug, $gateway_id ) );
	}

	/**
	 * Sanitize one value by schema type.
	 *
	 * @since 1.10.0
	 * @param string $type     Field type.
	 * @param mixed  $raw      Raw value.
	 * @param mixed  $existing Stored value, kept when a password is left blank.
	 * @return mixed
	 */
	public static function sanitize_value( string $type, $raw, $existing ) {
		switch ( $type ) {
			case 'bool':
				return ! empty( $raw );
			case 'password':
				$value = is_string( $raw ) ? trim( $raw ) : '';
				return '' === $value ? (string) $existing : $value;
			case 'url':
				return is_string( $raw ) ? esc_url_raw( $raw ) : '';
			case 'select':
				return is_string( $raw ) ? sanitize_key( $raw ) : '';
			default:
				return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
		}
	}
}
