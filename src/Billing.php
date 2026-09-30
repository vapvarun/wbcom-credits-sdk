<?php
/**
 * The buyer's billing identity, on the user, under WooCommerce's keys.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

use Wbcom\Credits\Gateways\Checkout_Settings;
use Wbcom\Credits\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * Billing lives on the USER (Woo's `billing_*` user meta, plus `billing_gst`
 * for a VAT / GST number), so an address entered for one Wbcom product is
 * there for every other and for WooCommerce, with no integration code. Each
 * purchase keeps its own snapshot (Transaction_Log `billing`), so editing the
 * profile never rewrites an old receipt.
 *
 * The fields asked for depend on the slug's billing mode: 'basic' (name,
 * email, country, optional company and tax number - enough for tax and a
 * receipt) or 'full' (adds the postal address, for invoicing).
 *
 * @since 1.9.0
 */
final class Billing {

	/**
	 * Field definitions for a slug, keyed by user meta key.
	 *
	 * @deprecated 1.10.0 The `label` key goes in 2.0.0; use schema() and label
	 *             the fields in the consumer's own text domain.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return array<string, array{label: string, required: bool, type: string, autocomplete: string}>
	 */
	public static function fields( string $slug ): array {
		$fields = array(
			'billing_first_name' => array( 'label' => __( 'First name', 'wbcom-credits-sdk' ), 'required' => true, 'type' => 'text', 'autocomplete' => 'given-name' ),
			'billing_last_name'  => array( 'label' => __( 'Last name', 'wbcom-credits-sdk' ), 'required' => true, 'type' => 'text', 'autocomplete' => 'family-name' ),
			'billing_email'      => array( 'label' => __( 'Email', 'wbcom-credits-sdk' ), 'required' => true, 'type' => 'email', 'autocomplete' => 'email' ),
			'billing_company'    => array( 'label' => __( 'Company', 'wbcom-credits-sdk' ), 'required' => false, 'type' => 'text', 'autocomplete' => 'organization' ),
			'billing_gst'        => array( 'label' => __( 'VAT / GST number', 'wbcom-credits-sdk' ), 'required' => false, 'type' => 'text', 'autocomplete' => 'off' ),
			'billing_address_1'  => array( 'label' => __( 'Street address', 'wbcom-credits-sdk' ), 'required' => true, 'type' => 'text', 'autocomplete' => 'address-line1' ),
			'billing_address_2'  => array( 'label' => __( 'Apartment, suite, etc.', 'wbcom-credits-sdk' ), 'required' => false, 'type' => 'text', 'autocomplete' => 'address-line2' ),
			'billing_city'       => array( 'label' => __( 'Town / City', 'wbcom-credits-sdk' ), 'required' => true, 'type' => 'text', 'autocomplete' => 'address-level2' ),
			'billing_state'      => array( 'label' => __( 'State / County', 'wbcom-credits-sdk' ), 'required' => false, 'type' => 'text', 'autocomplete' => 'address-level1' ),
			'billing_postcode'   => array( 'label' => __( 'Postcode / ZIP', 'wbcom-credits-sdk' ), 'required' => true, 'type' => 'text', 'autocomplete' => 'postal-code' ),
			'billing_country'    => array( 'label' => __( 'Country', 'wbcom-credits-sdk' ), 'required' => true, 'type' => 'country', 'autocomplete' => 'country' ),
		);

		if ( 'full' !== Checkout_Settings::get( $slug )['billing_mode'] ) {
			$fields = array_intersect_key(
				$fields,
				array_flip( array( 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_company', 'billing_gst', 'billing_country' ) )
			);
		}

		/**
		 * Filter the billing fields a slug asks for, keyed by user meta key.
		 *
		 * @since 1.9.0
		 *
		 * @param array  $fields Field definitions.
		 * @param string $slug   Plugin slug.
		 */
		return (array) apply_filters( 'wbcom_credits_billing_fields', $fields, $slug );
	}

	/**
	 * The billing fields a slug asks for, without display text: `required`,
	 * `type` and `autocomplete`, keyed by user meta key. The consumer labels
	 * them in its own text domain.
	 *
	 * @since 1.10.0
	 * @param string $slug Plugin slug.
	 * @return array<string, array{required: bool, type: string, autocomplete: string}>
	 */
	public static function schema( string $slug ): array {
		$out = array();
		foreach ( self::fields( $slug ) as $key => $field ) {
			unset( $field['label'] );
			$out[ $key ] = $field;
		}
		return $out;
	}

	/**
	 * A user's billing identity for a slug's fields. A missing email falls
	 * back to the account email so a first-time buyer never retypes it.
	 *
	 * @since 1.9.0
	 * @param int    $user_id User ID.
	 * @param string $slug    Plugin slug.
	 * @return array<string, string>
	 */
	public static function get( int $user_id, string $slug ): array {
		$out = array();
		foreach ( array_keys( self::fields( $slug ) ) as $key ) {
			$value = (string) get_user_meta( $user_id, $key, true );
			if ( '' === $value && 'billing_email' === $key ) {
				$user  = get_userdata( $user_id );
				$value = $user ? (string) $user->user_email : '';
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Save the submitted fields to the user, sanitised per type. Only the
	 * slug's fields are written; a country must be a known ISO code.
	 *
	 * @since 1.9.0
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Field key => raw value.
	 * @param string               $slug    Plugin slug.
	 * @return void
	 */
	public static function save( int $user_id, array $input, string $slug ): void {
		foreach ( self::fields( $slug ) as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw = (string) $input[ $key ];
			switch ( $field['type'] ) {
				case 'email':
					$value = sanitize_email( $raw );
					break;
				case 'country':
					$value = Countries::code_for( $raw );
					break;
				default:
					$value = sanitize_text_field( $raw );
			}
			update_user_meta( $user_id, $key, $value );
		}
	}

	/**
	 * Required fields still empty.
	 *
	 * @since 1.9.0
	 * @param array<string, string> $address Field key => value.
	 * @param string                $slug    Plugin slug.
	 * @return string[] Meta keys.
	 */
	public static function missing( array $address, string $slug ): array {
		$missing = array();
		foreach ( self::fields( $slug ) as $key => $field ) {
			if ( $field['required'] && '' === trim( (string) ( $address[ $key ] ?? '' ) ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}
}
