<?php
/**
 * Canonical currency registry (ISO 4217) — the ONE currency source.
 *
 * Per the Wbcom account/billing/currency standard (§5): a partial currency list
 * silently excludes customers, so this is the COMPLETE ISO 4217 set (148) with
 * correct minor units — zero-decimal (JPY, KRW, …) and three-decimal (BHD, KWD,
 * …) both exist and both break a naive ×100 gateway conversion.
 *
 * Every currency surface — pack settings, checkout, receipts, and the gateway
 * minor-unit conversion (Money) — reads from here, in every product that
 * bundles the SDK. Extend in ONE place via the
 * `wbcom_credits_currency_registry` filter. Defers to nothing at read time; the money
 * FORMAT (symbol position, separators) still comes from the site's currency
 * settings, this is only the code → { name, symbol, decimals } catalogue.
 *
 * @package Wbcom\Credits
 */

namespace Wbcom\Credits\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The ISO 4217 currency catalogue.
 */
final class Currencies {

	/**
	 * Three-decimal currency codes. Kept as an explicit override because some
	 * upstream registries default these to 2; the ×10^n gateway conversion must
	 * use 3 for them.
	 *
	 * @var string[]
	 */
	private const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * The canonical registry: code => { name, symbol, decimals }. Complete ISO 4217.
	 *
	 * @return array<string, array{name: string, symbol: string, decimals: int}>
	 */
	public static function registry(): array {
		$registry = array(
			'USD' => array(
				'name'     => __( 'US Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'EUR' => array(
				'name'     => __( 'Euro', 'wbcom-credits-sdk' ),
				'symbol'   => '€',
				'decimals' => 2,
			),
			'GBP' => array(
				'name'     => __( 'British Pound', 'wbcom-credits-sdk' ),
				'symbol'   => '£',
				'decimals' => 2,
			),
			'JPY' => array(
				'name'     => __( 'Japanese Yen', 'wbcom-credits-sdk' ),
				'symbol'   => '¥',
				'decimals' => 0,
			),
			'INR' => array(
				'name'     => __( 'Indian Rupee', 'wbcom-credits-sdk' ),
				'symbol'   => '₹',
				'decimals' => 2,
			),
			'AUD' => array(
				'name'     => __( 'Australian Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => 'A$',
				'decimals' => 2,
			),
			'CAD' => array(
				'name'     => __( 'Canadian Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => 'C$',
				'decimals' => 2,
			),
			'CHF' => array(
				'name'     => __( 'Swiss Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'CHF',
				'decimals' => 2,
			),
			'CNY' => array(
				'name'     => __( 'Chinese Yuan', 'wbcom-credits-sdk' ),
				'symbol'   => '¥',
				'decimals' => 2,
			),
			'KRW' => array(
				'name'     => __( 'South Korean Won', 'wbcom-credits-sdk' ),
				'symbol'   => '₩',
				'decimals' => 0,
			),
			'BRL' => array(
				'name'     => __( 'Brazilian Real', 'wbcom-credits-sdk' ),
				'symbol'   => 'R$',
				'decimals' => 2,
			),
			'MXN' => array(
				'name'     => __( 'Mexican Peso', 'wbcom-credits-sdk' ),
				'symbol'   => 'MX$',
				'decimals' => 2,
			),
			'SGD' => array(
				'name'     => __( 'Singapore Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => 'S$',
				'decimals' => 2,
			),
			'HKD' => array(
				'name'     => __( 'Hong Kong Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => 'HK$',
				'decimals' => 2,
			),
			'NOK' => array(
				'name'     => __( 'Norwegian Krone', 'wbcom-credits-sdk' ),
				'symbol'   => 'kr',
				'decimals' => 2,
			),
			'SEK' => array(
				'name'     => __( 'Swedish Krona', 'wbcom-credits-sdk' ),
				'symbol'   => 'kr',
				'decimals' => 2,
			),
			'DKK' => array(
				'name'     => __( 'Danish Krone', 'wbcom-credits-sdk' ),
				'symbol'   => 'kr',
				'decimals' => 2,
			),
			'NZD' => array(
				'name'     => __( 'New Zealand Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => 'NZ$',
				'decimals' => 2,
			),
			'ZAR' => array(
				'name'     => __( 'South African Rand', 'wbcom-credits-sdk' ),
				'symbol'   => 'R',
				'decimals' => 2,
			),
			'RUB' => array(
				'name'     => __( 'Russian Ruble', 'wbcom-credits-sdk' ),
				'symbol'   => '₽',
				'decimals' => 2,
			),
			'TRY' => array(
				'name'     => __( 'Turkish Lira', 'wbcom-credits-sdk' ),
				'symbol'   => '₺',
				'decimals' => 2,
			),
			'PLN' => array(
				'name'     => __( 'Polish Zloty', 'wbcom-credits-sdk' ),
				'symbol'   => 'zł',
				'decimals' => 2,
			),
			'THB' => array(
				'name'     => __( 'Thai Baht', 'wbcom-credits-sdk' ),
				'symbol'   => '฿',
				'decimals' => 2,
			),
			'MYR' => array(
				'name'     => __( 'Malaysian Ringgit', 'wbcom-credits-sdk' ),
				'symbol'   => 'RM',
				'decimals' => 2,
			),
			'PHP' => array(
				'name'     => __( 'Philippine Peso', 'wbcom-credits-sdk' ),
				'symbol'   => '₱',
				'decimals' => 2,
			),
			'IDR' => array(
				'name'     => __( 'Indonesian Rupiah', 'wbcom-credits-sdk' ),
				'symbol'   => 'Rp',
				'decimals' => 2,
			),
			'VND' => array(
				'name'     => __( 'Vietnamese Dong', 'wbcom-credits-sdk' ),
				'symbol'   => '₫',
				'decimals' => 0,
			),
			'AED' => array(
				'name'     => __( 'UAE Dirham', 'wbcom-credits-sdk' ),
				'symbol'   => 'د.إ',
				'decimals' => 2,
			),
			'SAR' => array(
				'name'     => __( 'Saudi Riyal', 'wbcom-credits-sdk' ),
				'symbol'   => '﷼',
				'decimals' => 2,
			),
			'EGP' => array(
				'name'     => __( 'Egyptian Pound', 'wbcom-credits-sdk' ),
				'symbol'   => 'E£',
				'decimals' => 2,
			),
			'NGN' => array(
				'name'     => __( 'Nigerian Naira', 'wbcom-credits-sdk' ),
				'symbol'   => '₦',
				'decimals' => 2,
			),
			'AFN' => array(
				'name'     => __( 'Afghan Afghani', 'wbcom-credits-sdk' ),
				'symbol'   => '؋',
				'decimals' => 2,
			),
			'ALL' => array(
				'name'     => __( 'Albanian Lek', 'wbcom-credits-sdk' ),
				'symbol'   => 'L',
				'decimals' => 2,
			),
			'AMD' => array(
				'name'     => __( 'Armenian Dram', 'wbcom-credits-sdk' ),
				'symbol'   => '֏',
				'decimals' => 2,
			),
			'ANG' => array(
				'name'     => __( 'Netherlands Antillean Guilder', 'wbcom-credits-sdk' ),
				'symbol'   => 'ƒ',
				'decimals' => 2,
			),
			'AOA' => array(
				'name'     => __( 'Angolan Kwanza', 'wbcom-credits-sdk' ),
				'symbol'   => 'Kz',
				'decimals' => 2,
			),
			'ARS' => array(
				'name'     => __( 'Argentine Peso', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'AWG' => array(
				'name'     => __( 'Aruban Florin', 'wbcom-credits-sdk' ),
				'symbol'   => 'ƒ',
				'decimals' => 2,
			),
			'AZN' => array(
				'name'     => __( 'Azerbaijani Manat', 'wbcom-credits-sdk' ),
				'symbol'   => '₼',
				'decimals' => 2,
			),
			'BAM' => array(
				'name'     => __( 'Bosnia-Herzegovina Mark', 'wbcom-credits-sdk' ),
				'symbol'   => 'KM',
				'decimals' => 2,
			),
			'BBD' => array(
				'name'     => __( 'Barbadian Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'BDT' => array(
				'name'     => __( 'Bangladeshi Taka', 'wbcom-credits-sdk' ),
				'symbol'   => '৳',
				'decimals' => 2,
			),
			'BGN' => array(
				'name'     => __( 'Bulgarian Lev', 'wbcom-credits-sdk' ),
				'symbol'   => 'лв',
				'decimals' => 2,
			),
			'BHD' => array(
				'name'     => __( 'Bahraini Dinar', 'wbcom-credits-sdk' ),
				'symbol'   => 'BD',
				'decimals' => 3,
			),
			'BIF' => array(
				'name'     => __( 'Burundian Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'FBu',
				'decimals' => 0,
			),
			'BMD' => array(
				'name'     => __( 'Bermudan Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'BND' => array(
				'name'     => __( 'Brunei Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'BOB' => array(
				'name'     => __( 'Bolivian Boliviano', 'wbcom-credits-sdk' ),
				'symbol'   => 'Bs.',
				'decimals' => 2,
			),
			'BSD' => array(
				'name'     => __( 'Bahamian Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'BTN' => array(
				'name'     => __( 'Bhutanese Ngultrum', 'wbcom-credits-sdk' ),
				'symbol'   => 'Nu.',
				'decimals' => 2,
			),
			'BWP' => array(
				'name'     => __( 'Botswanan Pula', 'wbcom-credits-sdk' ),
				'symbol'   => 'P',
				'decimals' => 2,
			),
			'BYN' => array(
				'name'     => __( 'Belarusian Ruble', 'wbcom-credits-sdk' ),
				'symbol'   => 'Br',
				'decimals' => 2,
			),
			'BZD' => array(
				'name'     => __( 'Belize Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'CDF' => array(
				'name'     => __( 'Congolese Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'FC',
				'decimals' => 2,
			),
			'CLP' => array(
				'name'     => __( 'Chilean Peso', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 0,
			),
			'COP' => array(
				'name'     => __( 'Colombian Peso', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'CRC' => array(
				'name'     => __( 'Costa Rican Colon', 'wbcom-credits-sdk' ),
				'symbol'   => '₡',
				'decimals' => 2,
			),
			'CUP' => array(
				'name'     => __( 'Cuban Peso', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'CVE' => array(
				'name'     => __( 'Cape Verdean Escudo', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'CZK' => array(
				'name'     => __( 'Czech Koruna', 'wbcom-credits-sdk' ),
				'symbol'   => 'Kc',
				'decimals' => 2,
			),
			'DJF' => array(
				'name'     => __( 'Djiboutian Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'Fdj',
				'decimals' => 0,
			),
			'DOP' => array(
				'name'     => __( 'Dominican Peso', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'DZD' => array(
				'name'     => __( 'Algerian Dinar', 'wbcom-credits-sdk' ),
				'symbol'   => 'DA',
				'decimals' => 2,
			),
			'ETB' => array(
				'name'     => __( 'Ethiopian Birr', 'wbcom-credits-sdk' ),
				'symbol'   => 'Br',
				'decimals' => 2,
			),
			'FJD' => array(
				'name'     => __( 'Fijian Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'GEL' => array(
				'name'     => __( 'Georgian Lari', 'wbcom-credits-sdk' ),
				'symbol'   => '₾',
				'decimals' => 2,
			),
			'GHS' => array(
				'name'     => __( 'Ghanaian Cedi', 'wbcom-credits-sdk' ),
				'symbol'   => '₵',
				'decimals' => 2,
			),
			'GMD' => array(
				'name'     => __( 'Gambian Dalasi', 'wbcom-credits-sdk' ),
				'symbol'   => 'D',
				'decimals' => 2,
			),
			'GNF' => array(
				'name'     => __( 'Guinean Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'FG',
				'decimals' => 0,
			),
			'GTQ' => array(
				'name'     => __( 'Guatemalan Quetzal', 'wbcom-credits-sdk' ),
				'symbol'   => 'Q',
				'decimals' => 2,
			),
			'GYD' => array(
				'name'     => __( 'Guyanaese Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'HNL' => array(
				'name'     => __( 'Honduran Lempira', 'wbcom-credits-sdk' ),
				'symbol'   => 'L',
				'decimals' => 2,
			),
			'HRK' => array(
				'name'     => __( 'Croatian Kuna', 'wbcom-credits-sdk' ),
				'symbol'   => 'kn',
				'decimals' => 2,
			),
			'HTG' => array(
				'name'     => __( 'Haitian Gourde', 'wbcom-credits-sdk' ),
				'symbol'   => 'G',
				'decimals' => 2,
			),
			'HUF' => array(
				'name'     => __( 'Hungarian Forint', 'wbcom-credits-sdk' ),
				'symbol'   => 'Ft',
				'decimals' => 2,
			),
			'ILS' => array(
				'name'     => __( 'Israeli Shekel', 'wbcom-credits-sdk' ),
				'symbol'   => '₪',
				'decimals' => 2,
			),
			'IQD' => array(
				'name'     => __( 'Iraqi Dinar', 'wbcom-credits-sdk' ),
				'symbol'   => 'ID',
				'decimals' => 3,
			),
			'IRR' => array(
				'name'     => __( 'Iranian Rial', 'wbcom-credits-sdk' ),
				'symbol'   => 'IR',
				'decimals' => 2,
			),
			'ISK' => array(
				'name'     => __( 'Icelandic Krona', 'wbcom-credits-sdk' ),
				'symbol'   => 'kr',
				'decimals' => 0,
			),
			'JMD' => array(
				'name'     => __( 'Jamaican Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'JOD' => array(
				'name'     => __( 'Jordanian Dinar', 'wbcom-credits-sdk' ),
				'symbol'   => 'JD',
				'decimals' => 3,
			),
			'KES' => array(
				'name'     => __( 'Kenyan Shilling', 'wbcom-credits-sdk' ),
				'symbol'   => 'KSh',
				'decimals' => 2,
			),
			'KGS' => array(
				'name'     => __( 'Kyrgystani Som', 'wbcom-credits-sdk' ),
				'symbol'   => 'c',
				'decimals' => 2,
			),
			'KHR' => array(
				'name'     => __( 'Cambodian Riel', 'wbcom-credits-sdk' ),
				'symbol'   => '៛',
				'decimals' => 2,
			),
			'KMF' => array(
				'name'     => __( 'Comorian Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'CF',
				'decimals' => 0,
			),
			'KWD' => array(
				'name'     => __( 'Kuwaiti Dinar', 'wbcom-credits-sdk' ),
				'symbol'   => 'KD',
				'decimals' => 3,
			),
			'KYD' => array(
				'name'     => __( 'Cayman Islands Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'KZT' => array(
				'name'     => __( 'Kazakhstani Tenge', 'wbcom-credits-sdk' ),
				'symbol'   => '₸',
				'decimals' => 2,
			),
			'LAK' => array(
				'name'     => __( 'Laotian Kip', 'wbcom-credits-sdk' ),
				'symbol'   => '₭',
				'decimals' => 2,
			),
			'LBP' => array(
				'name'     => __( 'Lebanese Pound', 'wbcom-credits-sdk' ),
				'symbol'   => 'LL',
				'decimals' => 2,
			),
			'LKR' => array(
				'name'     => __( 'Sri Lankan Rupee', 'wbcom-credits-sdk' ),
				'symbol'   => 'Rs',
				'decimals' => 2,
			),
			'LRD' => array(
				'name'     => __( 'Liberian Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'LSL' => array(
				'name'     => __( 'Lesotho Loti', 'wbcom-credits-sdk' ),
				'symbol'   => 'L',
				'decimals' => 2,
			),
			'LYD' => array(
				'name'     => __( 'Libyan Dinar', 'wbcom-credits-sdk' ),
				'symbol'   => 'LD',
				'decimals' => 3,
			),
			'MAD' => array(
				'name'     => __( 'Moroccan Dirham', 'wbcom-credits-sdk' ),
				'symbol'   => 'DH',
				'decimals' => 2,
			),
			'MDL' => array(
				'name'     => __( 'Moldovan Leu', 'wbcom-credits-sdk' ),
				'symbol'   => 'L',
				'decimals' => 2,
			),
			'MGA' => array(
				'name'     => __( 'Malagasy Ariary', 'wbcom-credits-sdk' ),
				'symbol'   => 'Ar',
				'decimals' => 2,
			),
			'MKD' => array(
				'name'     => __( 'Macedonian Denar', 'wbcom-credits-sdk' ),
				'symbol'   => 'den',
				'decimals' => 2,
			),
			'MMK' => array(
				'name'     => __( 'Myanmar Kyat', 'wbcom-credits-sdk' ),
				'symbol'   => 'K',
				'decimals' => 2,
			),
			'MNT' => array(
				'name'     => __( 'Mongolian Tugrik', 'wbcom-credits-sdk' ),
				'symbol'   => '₮',
				'decimals' => 2,
			),
			'MOP' => array(
				'name'     => __( 'Macanese Pataca', 'wbcom-credits-sdk' ),
				'symbol'   => 'MOP$',
				'decimals' => 2,
			),
			'MUR' => array(
				'name'     => __( 'Mauritian Rupee', 'wbcom-credits-sdk' ),
				'symbol'   => 'Rs',
				'decimals' => 2,
			),
			'MVR' => array(
				'name'     => __( 'Maldivian Rufiyaa', 'wbcom-credits-sdk' ),
				'symbol'   => 'Rf',
				'decimals' => 2,
			),
			'MWK' => array(
				'name'     => __( 'Malawian Kwacha', 'wbcom-credits-sdk' ),
				'symbol'   => 'MK',
				'decimals' => 2,
			),
			'MZN' => array(
				'name'     => __( 'Mozambican Metical', 'wbcom-credits-sdk' ),
				'symbol'   => 'MT',
				'decimals' => 2,
			),
			'NAD' => array(
				'name'     => __( 'Namibian Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'NIO' => array(
				'name'     => __( 'Nicaraguan Cordoba', 'wbcom-credits-sdk' ),
				'symbol'   => 'C$',
				'decimals' => 2,
			),
			'NPR' => array(
				'name'     => __( 'Nepalese Rupee', 'wbcom-credits-sdk' ),
				'symbol'   => 'Rs',
				'decimals' => 2,
			),
			'OMR' => array(
				'name'     => __( 'Omani Rial', 'wbcom-credits-sdk' ),
				'symbol'   => 'RO',
				'decimals' => 3,
			),
			'PAB' => array(
				'name'     => __( 'Panamanian Balboa', 'wbcom-credits-sdk' ),
				'symbol'   => 'B/.',
				'decimals' => 2,
			),
			'PEN' => array(
				'name'     => __( 'Peruvian Sol', 'wbcom-credits-sdk' ),
				'symbol'   => 'S/',
				'decimals' => 2,
			),
			'PGK' => array(
				'name'     => __( 'Papua New Guinean Kina', 'wbcom-credits-sdk' ),
				'symbol'   => 'K',
				'decimals' => 2,
			),
			'PKR' => array(
				'name'     => __( 'Pakistani Rupee', 'wbcom-credits-sdk' ),
				'symbol'   => 'Rs',
				'decimals' => 2,
			),
			'PYG' => array(
				'name'     => __( 'Paraguayan Guarani', 'wbcom-credits-sdk' ),
				'symbol'   => '₲',
				'decimals' => 0,
			),
			'QAR' => array(
				'name'     => __( 'Qatari Rial', 'wbcom-credits-sdk' ),
				'symbol'   => 'QR',
				'decimals' => 2,
			),
			'RON' => array(
				'name'     => __( 'Romanian Leu', 'wbcom-credits-sdk' ),
				'symbol'   => 'lei',
				'decimals' => 2,
			),
			'RSD' => array(
				'name'     => __( 'Serbian Dinar', 'wbcom-credits-sdk' ),
				'symbol'   => 'din.',
				'decimals' => 2,
			),
			'RWF' => array(
				'name'     => __( 'Rwandan Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'FRw',
				'decimals' => 0,
			),
			'SBD' => array(
				'name'     => __( 'Solomon Islands Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'SCR' => array(
				'name'     => __( 'Seychellois Rupee', 'wbcom-credits-sdk' ),
				'symbol'   => 'Rs',
				'decimals' => 2,
			),
			'SDG' => array(
				'name'     => __( 'Sudanese Pound', 'wbcom-credits-sdk' ),
				'symbol'   => 'SDG',
				'decimals' => 2,
			),
			'SLE' => array(
				'name'     => __( 'Sierra Leonean Leone', 'wbcom-credits-sdk' ),
				'symbol'   => 'Le',
				'decimals' => 2,
			),
			'SOS' => array(
				'name'     => __( 'Somali Shilling', 'wbcom-credits-sdk' ),
				'symbol'   => 'S',
				'decimals' => 2,
			),
			'SRD' => array(
				'name'     => __( 'Surinamese Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'SSP' => array(
				'name'     => __( 'South Sudanese Pound', 'wbcom-credits-sdk' ),
				'symbol'   => 'GBP',
				'decimals' => 2,
			),
			'SVC' => array(
				'name'     => __( 'Salvadoran Colon', 'wbcom-credits-sdk' ),
				'symbol'   => '₡',
				'decimals' => 2,
			),
			'SZL' => array(
				'name'     => __( 'Swazi Lilangeni', 'wbcom-credits-sdk' ),
				'symbol'   => 'E',
				'decimals' => 2,
			),
			'TJS' => array(
				'name'     => __( 'Tajikistani Somoni', 'wbcom-credits-sdk' ),
				'symbol'   => 'SM',
				'decimals' => 2,
			),
			'TMT' => array(
				'name'     => __( 'Turkmenistani Manat', 'wbcom-credits-sdk' ),
				'symbol'   => 'm',
				'decimals' => 2,
			),
			'TND' => array(
				'name'     => __( 'Tunisian Dinar', 'wbcom-credits-sdk' ),
				'symbol'   => 'DT',
				'decimals' => 3,
			),
			'TOP' => array(
				'name'     => __( 'Tongan Paanga', 'wbcom-credits-sdk' ),
				'symbol'   => 'T$',
				'decimals' => 2,
			),
			'TTD' => array(
				'name'     => __( 'Trinidad and Tobago Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'TWD' => array(
				'name'     => __( 'New Taiwan Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => 'NT$',
				'decimals' => 2,
			),
			'TZS' => array(
				'name'     => __( 'Tanzanian Shilling', 'wbcom-credits-sdk' ),
				'symbol'   => 'TSh',
				'decimals' => 2,
			),
			'UAH' => array(
				'name'     => __( 'Ukrainian Hryvnia', 'wbcom-credits-sdk' ),
				'symbol'   => '₴',
				'decimals' => 2,
			),
			'UGX' => array(
				'name'     => __( 'Ugandan Shilling', 'wbcom-credits-sdk' ),
				'symbol'   => 'USh',
				'decimals' => 0,
			),
			'UYU' => array(
				'name'     => __( 'Uruguayan Peso', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'UZS' => array(
				'name'     => __( 'Uzbekistani Som', 'wbcom-credits-sdk' ),
				'symbol'   => 'soum',
				'decimals' => 2,
			),
			'VES' => array(
				'name'     => __( 'Venezuelan Bolivar', 'wbcom-credits-sdk' ),
				'symbol'   => 'Bs.',
				'decimals' => 2,
			),
			'VUV' => array(
				'name'     => __( 'Vanuatu Vatu', 'wbcom-credits-sdk' ),
				'symbol'   => 'VT',
				'decimals' => 0,
			),
			'WST' => array(
				'name'     => __( 'Samoan Tala', 'wbcom-credits-sdk' ),
				'symbol'   => 'T',
				'decimals' => 2,
			),
			'XAF' => array(
				'name'     => __( 'Central African CFA Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'FCFA',
				'decimals' => 0,
			),
			'XCD' => array(
				'name'     => __( 'East Caribbean Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'XOF' => array(
				'name'     => __( 'West African CFA Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'CFA',
				'decimals' => 0,
			),
			'XPF' => array(
				'name'     => __( 'CFP Franc', 'wbcom-credits-sdk' ),
				'symbol'   => 'F',
				'decimals' => 0,
			),
			'YER' => array(
				'name'     => __( 'Yemeni Rial', 'wbcom-credits-sdk' ),
				'symbol'   => 'YR',
				'decimals' => 2,
			),
			'ZMW' => array(
				'name'     => __( 'Zambian Kwacha', 'wbcom-credits-sdk' ),
				'symbol'   => 'ZK',
				'decimals' => 2,
			),
			'ZWL' => array(
				'name'     => __( 'Zimbabwean Dollar', 'wbcom-credits-sdk' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
		);

		/**
		 * Filter the canonical currency registry. Add, remove, or adjust a
		 * currency (name / symbol / decimals) in ONE place and every currency
		 * surface updates. Preferred over the per-surface currency filters.
		 *
		 * @param array<string, array{name: string, symbol: string, decimals: int}> $registry Currency registry.
		 */
		return (array) apply_filters( 'wbcom_credits_currency_registry', $registry );
	}

	/**
	 * Code => display name (e.g. for a settings dropdown).
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::registry() as $code => $data ) {
			$out[ $code ] = (string) ( $data['name'] ?? $code );
		}
		return $out;
	}

	/**
	 * Display name for a currency code (falls back to the code).
	 *
	 * @param string $code ISO 4217 code.
	 * @return string
	 */
	public static function name( string $code ): string {
		$code     = strtoupper( $code );
		$registry = self::registry();
		return isset( $registry[ $code ] ) ? (string) $registry[ $code ]['name'] : $code;
	}

	/**
	 * Symbol for a currency code (falls back to the code). Filterable via
	 * `wbcom_credits_currency_symbols`.
	 *
	 * @param string $code ISO 4217 code.
	 * @return string
	 */
	public static function symbol( string $code ): string {
		$code     = strtoupper( $code );
		$registry = self::registry();

		$symbols = array();
		foreach ( $registry as $c => $data ) {
			$symbols[ $c ] = (string) $data['symbol'];
		}
		/**
		 * Filter currency symbols, code => symbol.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string, string> $symbols Symbols.
		 */
		$symbols = (array) apply_filters( 'wbcom_credits_currency_symbols', $symbols );

		return isset( $symbols[ $code ] ) ? (string) $symbols[ $code ] : $code;
	}

	/**
	 * Minor-unit decimal places for a currency (drives the ×10^n gateway
	 * conversion). Registry value, with the three-decimal override, filterable.
	 *
	 * @param string $code ISO 4217 code.
	 * @return int
	 */
	public static function decimals( string $code ): int {
		$code     = strtoupper( $code );
		$registry = self::registry();
		$decimals = isset( $registry[ $code ] ) ? (int) $registry[ $code ]['decimals'] : 2;

		if ( in_array( $code, self::THREE_DECIMAL, true ) ) {
			$decimals = 3;
		}

		/**
		 * Filter the number of decimal places (minor units) for a currency.
		 *
		 * @since 1.5.0 (as Money's filter)
		 *
		 * @param int    $decimals Number of decimal places.
		 * @param string $code     Currency code.
		 */
		return (int) apply_filters( 'wbcom_credits_currency_decimals', $decimals, $code );
	}
}
