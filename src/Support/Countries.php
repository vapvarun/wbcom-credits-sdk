<?php
/**
 * Canonical ISO 3166-1 alpha-2 country list.
 *
 * THE single source of truth for country code => label anywhere the SDK
 * surfaces a country choice (event editor, venues, filters, Pro add-ons).
 * Events/venues store the 2-letter UPPERCASE code (the `country char(2)`
 * columns); labels are translated display strings. Do NOT vendor another
 * country list elsewhere — reuse this class.
 *
 * @package Wbcom\Credits
 */

namespace Wbcom\Credits\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Static country-list helper.
 */
final class Countries {

	/**
	 * All ISO 3166-1 alpha-2 codes mapped to translated country names.
	 *
	 * Filterable so a site can trim the list (e.g. only countries it serves)
	 * or relabel entries without forking the editor.
	 *
	 * @return array<string, string> UPPERCASE code => translated label.
	 */
	public static function all(): array {
		// With WooCommerce active its list wins, so the two never disagree.
		if ( function_exists( 'WC' ) && isset( WC()->countries ) && is_object( WC()->countries ) ) {
			return (array) apply_filters( 'wbcom_credits_countries', (array) WC()->countries->get_countries() );
		}

		$countries = array(
			'AF' => __( 'Afghanistan', 'wbcom-credits-sdk' ),
			'AX' => __( 'Åland Islands', 'wbcom-credits-sdk' ),
			'AL' => __( 'Albania', 'wbcom-credits-sdk' ),
			'DZ' => __( 'Algeria', 'wbcom-credits-sdk' ),
			'AS' => __( 'American Samoa', 'wbcom-credits-sdk' ),
			'AD' => __( 'Andorra', 'wbcom-credits-sdk' ),
			'AO' => __( 'Angola', 'wbcom-credits-sdk' ),
			'AI' => __( 'Anguilla', 'wbcom-credits-sdk' ),
			'AQ' => __( 'Antarctica', 'wbcom-credits-sdk' ),
			'AG' => __( 'Antigua and Barbuda', 'wbcom-credits-sdk' ),
			'AR' => __( 'Argentina', 'wbcom-credits-sdk' ),
			'AM' => __( 'Armenia', 'wbcom-credits-sdk' ),
			'AW' => __( 'Aruba', 'wbcom-credits-sdk' ),
			'AU' => __( 'Australia', 'wbcom-credits-sdk' ),
			'AT' => __( 'Austria', 'wbcom-credits-sdk' ),
			'AZ' => __( 'Azerbaijan', 'wbcom-credits-sdk' ),
			'BS' => __( 'Bahamas', 'wbcom-credits-sdk' ),
			'BH' => __( 'Bahrain', 'wbcom-credits-sdk' ),
			'BD' => __( 'Bangladesh', 'wbcom-credits-sdk' ),
			'BB' => __( 'Barbados', 'wbcom-credits-sdk' ),
			'BY' => __( 'Belarus', 'wbcom-credits-sdk' ),
			'BE' => __( 'Belgium', 'wbcom-credits-sdk' ),
			'BZ' => __( 'Belize', 'wbcom-credits-sdk' ),
			'BJ' => __( 'Benin', 'wbcom-credits-sdk' ),
			'BM' => __( 'Bermuda', 'wbcom-credits-sdk' ),
			'BT' => __( 'Bhutan', 'wbcom-credits-sdk' ),
			'BO' => __( 'Bolivia', 'wbcom-credits-sdk' ),
			'BQ' => __( 'Bonaire, Sint Eustatius and Saba', 'wbcom-credits-sdk' ),
			'BA' => __( 'Bosnia and Herzegovina', 'wbcom-credits-sdk' ),
			'BW' => __( 'Botswana', 'wbcom-credits-sdk' ),
			'BV' => __( 'Bouvet Island', 'wbcom-credits-sdk' ),
			'BR' => __( 'Brazil', 'wbcom-credits-sdk' ),
			'IO' => __( 'British Indian Ocean Territory', 'wbcom-credits-sdk' ),
			'BN' => __( 'Brunei Darussalam', 'wbcom-credits-sdk' ),
			'BG' => __( 'Bulgaria', 'wbcom-credits-sdk' ),
			'BF' => __( 'Burkina Faso', 'wbcom-credits-sdk' ),
			'BI' => __( 'Burundi', 'wbcom-credits-sdk' ),
			'CV' => __( 'Cabo Verde', 'wbcom-credits-sdk' ),
			'KH' => __( 'Cambodia', 'wbcom-credits-sdk' ),
			'CM' => __( 'Cameroon', 'wbcom-credits-sdk' ),
			'CA' => __( 'Canada', 'wbcom-credits-sdk' ),
			'KY' => __( 'Cayman Islands', 'wbcom-credits-sdk' ),
			'CF' => __( 'Central African Republic', 'wbcom-credits-sdk' ),
			'TD' => __( 'Chad', 'wbcom-credits-sdk' ),
			'CL' => __( 'Chile', 'wbcom-credits-sdk' ),
			'CN' => __( 'China', 'wbcom-credits-sdk' ),
			'CX' => __( 'Christmas Island', 'wbcom-credits-sdk' ),
			'CC' => __( 'Cocos (Keeling) Islands', 'wbcom-credits-sdk' ),
			'CO' => __( 'Colombia', 'wbcom-credits-sdk' ),
			'KM' => __( 'Comoros', 'wbcom-credits-sdk' ),
			'CG' => __( 'Congo', 'wbcom-credits-sdk' ),
			'CD' => __( 'Congo, Democratic Republic of the', 'wbcom-credits-sdk' ),
			'CK' => __( 'Cook Islands', 'wbcom-credits-sdk' ),
			'CR' => __( 'Costa Rica', 'wbcom-credits-sdk' ),
			'CI' => __( 'Côte d\'Ivoire', 'wbcom-credits-sdk' ),
			'HR' => __( 'Croatia', 'wbcom-credits-sdk' ),
			'CU' => __( 'Cuba', 'wbcom-credits-sdk' ),
			'CW' => __( 'Curaçao', 'wbcom-credits-sdk' ),
			'CY' => __( 'Cyprus', 'wbcom-credits-sdk' ),
			'CZ' => __( 'Czechia', 'wbcom-credits-sdk' ),
			'DK' => __( 'Denmark', 'wbcom-credits-sdk' ),
			'DJ' => __( 'Djibouti', 'wbcom-credits-sdk' ),
			'DM' => __( 'Dominica', 'wbcom-credits-sdk' ),
			'DO' => __( 'Dominican Republic', 'wbcom-credits-sdk' ),
			'EC' => __( 'Ecuador', 'wbcom-credits-sdk' ),
			'EG' => __( 'Egypt', 'wbcom-credits-sdk' ),
			'SV' => __( 'El Salvador', 'wbcom-credits-sdk' ),
			'GQ' => __( 'Equatorial Guinea', 'wbcom-credits-sdk' ),
			'ER' => __( 'Eritrea', 'wbcom-credits-sdk' ),
			'EE' => __( 'Estonia', 'wbcom-credits-sdk' ),
			'SZ' => __( 'Eswatini', 'wbcom-credits-sdk' ),
			'ET' => __( 'Ethiopia', 'wbcom-credits-sdk' ),
			'FK' => __( 'Falkland Islands', 'wbcom-credits-sdk' ),
			'FO' => __( 'Faroe Islands', 'wbcom-credits-sdk' ),
			'FJ' => __( 'Fiji', 'wbcom-credits-sdk' ),
			'FI' => __( 'Finland', 'wbcom-credits-sdk' ),
			'FR' => __( 'France', 'wbcom-credits-sdk' ),
			'GF' => __( 'French Guiana', 'wbcom-credits-sdk' ),
			'PF' => __( 'French Polynesia', 'wbcom-credits-sdk' ),
			'TF' => __( 'French Southern Territories', 'wbcom-credits-sdk' ),
			'GA' => __( 'Gabon', 'wbcom-credits-sdk' ),
			'GM' => __( 'Gambia', 'wbcom-credits-sdk' ),
			'GE' => __( 'Georgia', 'wbcom-credits-sdk' ),
			'DE' => __( 'Germany', 'wbcom-credits-sdk' ),
			'GH' => __( 'Ghana', 'wbcom-credits-sdk' ),
			'GI' => __( 'Gibraltar', 'wbcom-credits-sdk' ),
			'GR' => __( 'Greece', 'wbcom-credits-sdk' ),
			'GL' => __( 'Greenland', 'wbcom-credits-sdk' ),
			'GD' => __( 'Grenada', 'wbcom-credits-sdk' ),
			'GP' => __( 'Guadeloupe', 'wbcom-credits-sdk' ),
			'GU' => __( 'Guam', 'wbcom-credits-sdk' ),
			'GT' => __( 'Guatemala', 'wbcom-credits-sdk' ),
			'GG' => __( 'Guernsey', 'wbcom-credits-sdk' ),
			'GN' => __( 'Guinea', 'wbcom-credits-sdk' ),
			'GW' => __( 'Guinea-Bissau', 'wbcom-credits-sdk' ),
			'GY' => __( 'Guyana', 'wbcom-credits-sdk' ),
			'HT' => __( 'Haiti', 'wbcom-credits-sdk' ),
			'HM' => __( 'Heard Island and McDonald Islands', 'wbcom-credits-sdk' ),
			'VA' => __( 'Holy See', 'wbcom-credits-sdk' ),
			'HN' => __( 'Honduras', 'wbcom-credits-sdk' ),
			'HK' => __( 'Hong Kong', 'wbcom-credits-sdk' ),
			'HU' => __( 'Hungary', 'wbcom-credits-sdk' ),
			'IS' => __( 'Iceland', 'wbcom-credits-sdk' ),
			'IN' => __( 'India', 'wbcom-credits-sdk' ),
			'ID' => __( 'Indonesia', 'wbcom-credits-sdk' ),
			'IR' => __( 'Iran', 'wbcom-credits-sdk' ),
			'IQ' => __( 'Iraq', 'wbcom-credits-sdk' ),
			'IE' => __( 'Ireland', 'wbcom-credits-sdk' ),
			'IM' => __( 'Isle of Man', 'wbcom-credits-sdk' ),
			'IL' => __( 'Israel', 'wbcom-credits-sdk' ),
			'IT' => __( 'Italy', 'wbcom-credits-sdk' ),
			'JM' => __( 'Jamaica', 'wbcom-credits-sdk' ),
			'JP' => __( 'Japan', 'wbcom-credits-sdk' ),
			'JE' => __( 'Jersey', 'wbcom-credits-sdk' ),
			'JO' => __( 'Jordan', 'wbcom-credits-sdk' ),
			'KZ' => __( 'Kazakhstan', 'wbcom-credits-sdk' ),
			'KE' => __( 'Kenya', 'wbcom-credits-sdk' ),
			'KI' => __( 'Kiribati', 'wbcom-credits-sdk' ),
			'KP' => __( 'Korea, Democratic People\'s Republic of', 'wbcom-credits-sdk' ),
			'KR' => __( 'Korea, Republic of', 'wbcom-credits-sdk' ),
			'KW' => __( 'Kuwait', 'wbcom-credits-sdk' ),
			'KG' => __( 'Kyrgyzstan', 'wbcom-credits-sdk' ),
			'LA' => __( 'Lao People\'s Democratic Republic', 'wbcom-credits-sdk' ),
			'LV' => __( 'Latvia', 'wbcom-credits-sdk' ),
			'LB' => __( 'Lebanon', 'wbcom-credits-sdk' ),
			'LS' => __( 'Lesotho', 'wbcom-credits-sdk' ),
			'LR' => __( 'Liberia', 'wbcom-credits-sdk' ),
			'LY' => __( 'Libya', 'wbcom-credits-sdk' ),
			'LI' => __( 'Liechtenstein', 'wbcom-credits-sdk' ),
			'LT' => __( 'Lithuania', 'wbcom-credits-sdk' ),
			'LU' => __( 'Luxembourg', 'wbcom-credits-sdk' ),
			'MO' => __( 'Macao', 'wbcom-credits-sdk' ),
			'MG' => __( 'Madagascar', 'wbcom-credits-sdk' ),
			'MW' => __( 'Malawi', 'wbcom-credits-sdk' ),
			'MY' => __( 'Malaysia', 'wbcom-credits-sdk' ),
			'MV' => __( 'Maldives', 'wbcom-credits-sdk' ),
			'ML' => __( 'Mali', 'wbcom-credits-sdk' ),
			'MT' => __( 'Malta', 'wbcom-credits-sdk' ),
			'MH' => __( 'Marshall Islands', 'wbcom-credits-sdk' ),
			'MQ' => __( 'Martinique', 'wbcom-credits-sdk' ),
			'MR' => __( 'Mauritania', 'wbcom-credits-sdk' ),
			'MU' => __( 'Mauritius', 'wbcom-credits-sdk' ),
			'YT' => __( 'Mayotte', 'wbcom-credits-sdk' ),
			'MX' => __( 'Mexico', 'wbcom-credits-sdk' ),
			'FM' => __( 'Micronesia', 'wbcom-credits-sdk' ),
			'MD' => __( 'Moldova', 'wbcom-credits-sdk' ),
			'MC' => __( 'Monaco', 'wbcom-credits-sdk' ),
			'MN' => __( 'Mongolia', 'wbcom-credits-sdk' ),
			'ME' => __( 'Montenegro', 'wbcom-credits-sdk' ),
			'MS' => __( 'Montserrat', 'wbcom-credits-sdk' ),
			'MA' => __( 'Morocco', 'wbcom-credits-sdk' ),
			'MZ' => __( 'Mozambique', 'wbcom-credits-sdk' ),
			'MM' => __( 'Myanmar', 'wbcom-credits-sdk' ),
			'NA' => __( 'Namibia', 'wbcom-credits-sdk' ),
			'NR' => __( 'Nauru', 'wbcom-credits-sdk' ),
			'NP' => __( 'Nepal', 'wbcom-credits-sdk' ),
			'NL' => __( 'Netherlands', 'wbcom-credits-sdk' ),
			'NC' => __( 'New Caledonia', 'wbcom-credits-sdk' ),
			'NZ' => __( 'New Zealand', 'wbcom-credits-sdk' ),
			'NI' => __( 'Nicaragua', 'wbcom-credits-sdk' ),
			'NE' => __( 'Niger', 'wbcom-credits-sdk' ),
			'NG' => __( 'Nigeria', 'wbcom-credits-sdk' ),
			'NU' => __( 'Niue', 'wbcom-credits-sdk' ),
			'NF' => __( 'Norfolk Island', 'wbcom-credits-sdk' ),
			'MK' => __( 'North Macedonia', 'wbcom-credits-sdk' ),
			'MP' => __( 'Northern Mariana Islands', 'wbcom-credits-sdk' ),
			'NO' => __( 'Norway', 'wbcom-credits-sdk' ),
			'OM' => __( 'Oman', 'wbcom-credits-sdk' ),
			'PK' => __( 'Pakistan', 'wbcom-credits-sdk' ),
			'PW' => __( 'Palau', 'wbcom-credits-sdk' ),
			'PS' => __( 'Palestine, State of', 'wbcom-credits-sdk' ),
			'PA' => __( 'Panama', 'wbcom-credits-sdk' ),
			'PG' => __( 'Papua New Guinea', 'wbcom-credits-sdk' ),
			'PY' => __( 'Paraguay', 'wbcom-credits-sdk' ),
			'PE' => __( 'Peru', 'wbcom-credits-sdk' ),
			'PH' => __( 'Philippines', 'wbcom-credits-sdk' ),
			'PN' => __( 'Pitcairn', 'wbcom-credits-sdk' ),
			'PL' => __( 'Poland', 'wbcom-credits-sdk' ),
			'PT' => __( 'Portugal', 'wbcom-credits-sdk' ),
			'PR' => __( 'Puerto Rico', 'wbcom-credits-sdk' ),
			'QA' => __( 'Qatar', 'wbcom-credits-sdk' ),
			'RE' => __( 'Réunion', 'wbcom-credits-sdk' ),
			'RO' => __( 'Romania', 'wbcom-credits-sdk' ),
			'RU' => __( 'Russian Federation', 'wbcom-credits-sdk' ),
			'RW' => __( 'Rwanda', 'wbcom-credits-sdk' ),
			'BL' => __( 'Saint Barthélemy', 'wbcom-credits-sdk' ),
			'SH' => __( 'Saint Helena, Ascension and Tristan da Cunha', 'wbcom-credits-sdk' ),
			'KN' => __( 'Saint Kitts and Nevis', 'wbcom-credits-sdk' ),
			'LC' => __( 'Saint Lucia', 'wbcom-credits-sdk' ),
			'MF' => __( 'Saint Martin (French part)', 'wbcom-credits-sdk' ),
			'PM' => __( 'Saint Pierre and Miquelon', 'wbcom-credits-sdk' ),
			'VC' => __( 'Saint Vincent and the Grenadines', 'wbcom-credits-sdk' ),
			'WS' => __( 'Samoa', 'wbcom-credits-sdk' ),
			'SM' => __( 'San Marino', 'wbcom-credits-sdk' ),
			'ST' => __( 'Sao Tome and Principe', 'wbcom-credits-sdk' ),
			'SA' => __( 'Saudi Arabia', 'wbcom-credits-sdk' ),
			'SN' => __( 'Senegal', 'wbcom-credits-sdk' ),
			'RS' => __( 'Serbia', 'wbcom-credits-sdk' ),
			'SC' => __( 'Seychelles', 'wbcom-credits-sdk' ),
			'SL' => __( 'Sierra Leone', 'wbcom-credits-sdk' ),
			'SG' => __( 'Singapore', 'wbcom-credits-sdk' ),
			'SX' => __( 'Sint Maarten (Dutch part)', 'wbcom-credits-sdk' ),
			'SK' => __( 'Slovakia', 'wbcom-credits-sdk' ),
			'SI' => __( 'Slovenia', 'wbcom-credits-sdk' ),
			'SB' => __( 'Solomon Islands', 'wbcom-credits-sdk' ),
			'SO' => __( 'Somalia', 'wbcom-credits-sdk' ),
			'ZA' => __( 'South Africa', 'wbcom-credits-sdk' ),
			'GS' => __( 'South Georgia and the South Sandwich Islands', 'wbcom-credits-sdk' ),
			'SS' => __( 'South Sudan', 'wbcom-credits-sdk' ),
			'ES' => __( 'Spain', 'wbcom-credits-sdk' ),
			'LK' => __( 'Sri Lanka', 'wbcom-credits-sdk' ),
			'SD' => __( 'Sudan', 'wbcom-credits-sdk' ),
			'SR' => __( 'Suriname', 'wbcom-credits-sdk' ),
			'SJ' => __( 'Svalbard and Jan Mayen', 'wbcom-credits-sdk' ),
			'SE' => __( 'Sweden', 'wbcom-credits-sdk' ),
			'CH' => __( 'Switzerland', 'wbcom-credits-sdk' ),
			'SY' => __( 'Syrian Arab Republic', 'wbcom-credits-sdk' ),
			'TW' => __( 'Taiwan', 'wbcom-credits-sdk' ),
			'TJ' => __( 'Tajikistan', 'wbcom-credits-sdk' ),
			'TZ' => __( 'Tanzania', 'wbcom-credits-sdk' ),
			'TH' => __( 'Thailand', 'wbcom-credits-sdk' ),
			'TL' => __( 'Timor-Leste', 'wbcom-credits-sdk' ),
			'TG' => __( 'Togo', 'wbcom-credits-sdk' ),
			'TK' => __( 'Tokelau', 'wbcom-credits-sdk' ),
			'TO' => __( 'Tonga', 'wbcom-credits-sdk' ),
			'TT' => __( 'Trinidad and Tobago', 'wbcom-credits-sdk' ),
			'TN' => __( 'Tunisia', 'wbcom-credits-sdk' ),
			'TR' => __( 'Türkiye', 'wbcom-credits-sdk' ),
			'TM' => __( 'Turkmenistan', 'wbcom-credits-sdk' ),
			'TC' => __( 'Turks and Caicos Islands', 'wbcom-credits-sdk' ),
			'TV' => __( 'Tuvalu', 'wbcom-credits-sdk' ),
			'UG' => __( 'Uganda', 'wbcom-credits-sdk' ),
			'UA' => __( 'Ukraine', 'wbcom-credits-sdk' ),
			'AE' => __( 'United Arab Emirates', 'wbcom-credits-sdk' ),
			'GB' => __( 'United Kingdom', 'wbcom-credits-sdk' ),
			'US' => __( 'United States', 'wbcom-credits-sdk' ),
			'UM' => __( 'United States Minor Outlying Islands', 'wbcom-credits-sdk' ),
			'UY' => __( 'Uruguay', 'wbcom-credits-sdk' ),
			'UZ' => __( 'Uzbekistan', 'wbcom-credits-sdk' ),
			'VU' => __( 'Vanuatu', 'wbcom-credits-sdk' ),
			'VE' => __( 'Venezuela', 'wbcom-credits-sdk' ),
			'VN' => __( 'Viet Nam', 'wbcom-credits-sdk' ),
			'VG' => __( 'Virgin Islands (British)', 'wbcom-credits-sdk' ),
			'VI' => __( 'Virgin Islands (U.S.)', 'wbcom-credits-sdk' ),
			'WF' => __( 'Wallis and Futuna', 'wbcom-credits-sdk' ),
			'EH' => __( 'Western Sahara', 'wbcom-credits-sdk' ),
			'YE' => __( 'Yemen', 'wbcom-credits-sdk' ),
			'ZM' => __( 'Zambia', 'wbcom-credits-sdk' ),
			'ZW' => __( 'Zimbabwe', 'wbcom-credits-sdk' ),
		);

		/**
		 * Filter the canonical country list.
		 *
		 * @param array<string, string> $countries UPPERCASE ISO code => label.
		 */
		return (array) apply_filters( 'wbcom_credits_countries', $countries );
	}

	/**
	 * Every supported country code, uppercase ISO 3166-1 alpha-2.
	 *
	 * @since 1.10.0
	 * @return list<string>
	 */
	public static function codes(): array {
		return array_map( 'strval', array_keys( self::all() ) );
	}

	/**
	 * A country's name in the given locale, from PHP intl (CLDR data, so
	 * every language already has it and there is nothing to translate).
	 * Falls back to the code when intl is missing or does not know it.
	 *
	 * @since 1.10.0
	 * @param string $code   ISO code (any case).
	 * @param string $locale WordPress locale, e.g. de_DE. Defaults to the current one.
	 * @return string
	 */
	public static function display_name( string $code, string $locale = '' ): string {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code || ! class_exists( '\\Locale' ) ) {
			return $code;
		}
		if ( '' === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : 'en_US';
		}
		$name = \Locale::getDisplayRegion( 'und-' . $code, $locale );
		// intl answers an unknown code with its own "Unknown Region" label.
		$unknown = \Locale::getDisplayRegion( 'und-ZZ', $locale );

		return ( is_string( $name ) && '' !== $name && $name !== $unknown && strtoupper( $name ) !== $code ) ? $name : $code;
	}

	/**
	 * Display label for a stored country code.
	 *
	 * @deprecated 1.10.0 Use display_name(); the translated list goes in 2.0.0.
	 *
	 * @param string $code Stored ISO code (any case).
	 * @return string Translated label, or the normalised code itself when unknown.
	 */
	public static function label( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code ) {
			return '';
		}
		$all = self::all();

		return isset( $all[ $code ] ) ? $all[ $code ] : $code;
	}

	/**
	 * Common English variants → ISO code (lowercase variant => code).
	 *
	 * Migration sources (TEC, EventON, …) carry free-typed country names, so
	 * the label inversion in code_for() gets a small alias net for frequent
	 * spellings that don't match the canonical labels verbatim.
	 *
	 * @var array<string, string>
	 */
	private const ENGLISH_ALIASES = array(
		'usa'                      => 'US',
		'u.s.'                     => 'US',
		'u.s.a.'                   => 'US',
		'united states of america' => 'US',
		'uk'                       => 'GB',
		'u.k.'                     => 'GB',
		'great britain'            => 'GB',
		'uae'                      => 'AE',
		'russia'                   => 'RU',
		'south korea'              => 'KR',
		'vietnam'                  => 'VN',
		'czech republic'           => 'CZ',
		'the netherlands'          => 'NL',
		'holland'                  => 'NL',
	);

	/**
	 * Resolve a raw country value to the canonical UPPERCASE ISO 3166-1
	 * alpha-2 code. Accepts an ISO code (passthrough), a translated label
	 * from all(), or a common English variant like 'USA'. THE single
	 * label→code inversion — importers/mappers must call this instead of
	 * vendoring their own.
	 *
	 * @param string $label_or_code Raw country value (code, label, variant).
	 * @return string Uppercase ISO code, or '' when unknown.
	 */
	public static function code_for( string $label_or_code ): string {
		$value = trim( $label_or_code );
		if ( '' === $value ) {
			return '';
		}

		$all = self::all();

		// ISO code passthrough.
		$upper = strtoupper( $value );
		if ( 2 === strlen( $upper ) && isset( $all[ $upper ] ) ) {
			return $upper;
		}

		// Translated-label inversion (built once per request, like label()
		// callers this reflects the `wbcom_credits_countries` filter at first use).
		static $by_label = null;
		if ( null === $by_label ) {
			$by_label = array();
			foreach ( $all as $code => $label ) {
				$by_label[ strtolower( $label ) ] = (string) $code;
			}
		}

		$lower = strtolower( $value );
		if ( isset( $by_label[ $lower ] ) ) {
			return $by_label[ $lower ];
		}

		return self::ENGLISH_ALIASES[ $lower ] ?? '';
	}
}
