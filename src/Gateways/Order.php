<?php
/**
 * What a checkout charges: pack price, coupon, tax, total.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a checkout's money is computed, server-side, before any
 * gateway is involved. The gateway only ever sees `total`; the parts travel
 * with the pending checkout and land on the Transaction_Log row, so the
 * receipt, coupon usage and revenue all read the same numbers.
 *
 * All amounts are minor units of `currency` (Money::to_minor()).
 *
 * @since 1.9.0
 */
final class Order {

	/**
	 * Build the order for a resolved pack or custom amount.
	 *
	 * @since 1.9.0
	 * @param string               $slug     Plugin slug.
	 * @param array<string, mixed> $resolved Pricing::resolve() result.
	 * @param string               $coupon   Coupon code as typed ('' for none).
	 * @param array<string, mixed> $billing  Billing snapshot.
	 * @return array{credits: int, currency: string, subtotal: int, discount: int, coupon: string, tax_rate: float, tax: int, total: int, billing: array, expires_days: int}|\WP_Error
	 */
	public static function build( string $slug, array $resolved, string $coupon, array $billing ) {
		$currency = strtoupper( (string) $resolved['currency'] );
		$subtotal = (int) $resolved['price_cents'];
		$discount = 0;
		$code     = '';

		if ( '' !== trim( $coupon ) ) {
			$found = Coupons::find( $slug, $coupon );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$discount = Coupons::discount( $found, $subtotal, $currency );
			$code     = $found['code'];
		}

		$rate = (float) Checkout_Settings::get( $slug )['tax_rate'];
		$net  = $subtotal - $discount;
		$tax  = (int) round( $net * $rate / 100 );

		return array(
			'credits'      => (int) $resolved['credits'],
			'currency'     => $currency,
			'subtotal'     => $subtotal,
			'discount'     => $discount,
			'coupon'       => $code,
			'tax_rate'     => $rate,
			'tax'          => $tax,
			'total'        => $net + $tax,
			'billing'      => $billing,
			'expires_days' => max( 0, (int) ( $resolved['expires_days'] ?? 0 ) ),
		);
	}
}
