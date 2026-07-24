<?php
/**
 * Money — currency precision helpers for consumers whose credits are money.
 *
 * The ledger column is `amount INT`, which is deliberate: an append-only ledger
 * of integers cannot drift the way summed floats do. It also means the SDK
 * never has to guess what a credit is worth — a credit is whatever unit the
 * consumer registers.
 *
 * That works cleanly for token-style consumers ("10 credits = 10 downloads").
 * It needs care for money-style consumers, where a credit IS a currency amount:
 * casting 147.35 to an int stores 147 and loses 35 cents on every transaction.
 * The fix is the same one payment processors use — store MINOR UNITS (cents)
 * rather than a decimal — and this class is the shared implementation of it, so
 * each consumer plugin does not reinvent the conversion and get the edge cases
 * wrong.
 *
 * Those edge cases are the reason this is not a bare `* 100`:
 *
 *  - Not every currency has two decimals. JPY, KRW and VND have no minor unit
 *    at all (¥100 is 100 yen, not 10,000), while the Gulf dinars (KWD, BHD,
 *    OMR, TND) are divided into thousandths. Multiplying those by 100 either
 *    invents precision that does not exist or truncates the third decimal.
 *  - Binary floating point cannot represent 147.35 exactly: `147.35 * 100` is
 *    14734.999999999998, so a cast truncates back to 14734. Rounding is
 *    mandatory, not cosmetic.
 *
 * The SDK does NOT convert amounts on the consumer's behalf. Doing so would
 * silently change the meaning of every existing ledger row in every plugin
 * already using the SDK. Consumers opt in by converting at their own boundary:
 *
 *     $minor = Money::to_minor( 147.35, 'USD' );   // 14735
 *     Credits::topup( $slug, $user_id, $minor );
 *     $shown = Money::to_major( $balance, 'USD' ); // 147.35
 *
 * Contract for money-denominated consumers: every row for that consumer —
 * including rows written by the payment adapters and gateways — must be in the
 * same minor units. Mixing units in one ledger is what makes a balance wrong.
 *
 * @package Wbcom\Credits
 * @since   1.5.0
 */

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Currency precision helpers.
 *
 * @since 1.5.0
 */
final class Money {

	/**
	 * Currencies with no minor unit (ISO 4217 exponent 0).
	 *
	 * @var string[]
	 */
	private const ZERO_DECIMAL = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * Currencies divided into thousandths (ISO 4217 exponent 3).
	 *
	 * @var string[]
	 */
	private const THREE_DECIMAL = array(
		'BHD',
		'IQD',
		'JOD',
		'KWD',
		'LYD',
		'OMR',
		'TND',
	);

	/**
	 * Decimal places for a currency.
	 *
	 * @since 1.5.0
	 * @param string $currency ISO 4217 code, e.g. 'USD'.
	 * @return int 0, 2 or 3.
	 */
	public static function decimals_for( string $currency ): int {
		$code = strtoupper( trim( $currency ) );

		if ( in_array( $code, self::ZERO_DECIMAL, true ) ) {
			$decimals = 0;
		} elseif ( in_array( $code, self::THREE_DECIMAL, true ) ) {
			$decimals = 3;
		} else {
			$decimals = 2;
		}

		/**
		 * Filter the decimal precision used for a currency.
		 *
		 * Lets a consumer support a currency the SDK does not know about, or
		 * override precision for a non-standard denomination.
		 *
		 * @since 1.5.0
		 * @param int    $decimals Decimal places.
		 * @param string $code     Uppercased currency code.
		 */
		return (int) apply_filters( 'wbcom_credits_currency_decimals', $decimals, $code );
	}

	/**
	 * Multiplier between a major-unit amount and its minor units.
	 *
	 * @since 1.5.0
	 * @param string $currency ISO 4217 code.
	 * @return int 1, 100 or 1000.
	 */
	public static function factor_for( string $currency ): int {
		return (int) pow( 10, self::decimals_for( $currency ) );
	}

	/**
	 * Convert a money amount to the minor units stored in the ledger.
	 *
	 * Rounds rather than casts, because `147.35 * 100` is 14734.999... in
	 * binary floating point and a cast would truncate the cent back off.
	 *
	 * @since 1.5.0
	 * @param float|int|string $amount   Amount in major units, e.g. 147.35.
	 * @param string           $currency ISO 4217 code.
	 * @return int Amount in minor units, e.g. 14735.
	 */
	public static function to_minor( $amount, string $currency = 'USD' ): int {
		return (int) round( (float) $amount * self::factor_for( $currency ) );
	}

	/**
	 * Convert ledger minor units back to a major-unit money amount.
	 *
	 * @since 1.5.0
	 * @param int|float|string $minor    Amount in minor units, e.g. 14735.
	 * @param string           $currency ISO 4217 code.
	 * @return float Amount in major units, e.g. 147.35.
	 */
	public static function to_major( $minor, string $currency = 'USD' ): float {
		return round( (int) $minor / self::factor_for( $currency ), self::decimals_for( $currency ) );
	}
}
