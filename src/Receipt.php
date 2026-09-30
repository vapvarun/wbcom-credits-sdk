<?php
/**
 * Receipts for credit purchases.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

use Wbcom\Credits\Gateways\Checkout_Settings;
use Wbcom\Credits\Gateways\Transaction_Log;
use Wbcom\Credits\Support\Countries;
use Wbcom\Credits\Support\Currencies;

defined( 'ABSPATH' ) || exit;

/**
 * Every paid order (Transaction_Log checkout row) has a receipt: the numbers
 * charged, the coupon, tax, the buyer's billing snapshot and the seller from
 * Checkout_Settings. {@see self::url()} is a printable page only the buyer and
 * administrators can open; consumers link to it from their receipt emails and
 * purchase history. Override the page with a theme template at
 * `wbcom-credits/{slug}/frontend/receipt.php` (Template lookup order).
 *
 * @since 1.9.0
 */
final class Receipt {

	/**
	 * Query var carrying the row id.
	 *
	 * @var string
	 */
	private const VAR = 'wbcom_credits_receipt';

	/**
	 * Printable receipt URL.
	 *
	 * @since 1.9.0
	 * @param string $slug   Plugin slug.
	 * @param int    $log_id Transaction_Log row id.
	 * @return string
	 */
	public static function url( string $slug, int $log_id ): string {
		$url = add_query_arg(
			array(
				self::VAR => $log_id,
				'slug'    => $slug,
			),
			home_url( '/' )
		);

		/**
		 * Point receipt links at the consumer's own receipt page.
		 *
		 * @since 1.10.0
		 *
		 * @param string $url    SDK default (served by maybe_render(), deprecated).
		 * @param string $slug   Plugin slug.
		 * @param int    $log_id Transaction log row id.
		 */
		return (string) apply_filters( 'wbcom_credits_receipt_url', $url, $slug, $log_id );
	}

	/**
	 * The receipt a user may see, or null. The buyer sees their own; a user
	 * who can manage options sees any. Use it to guard the consumer's own
	 * receipt page.
	 *
	 * @since 1.10.0
	 * @param int    $user_id User asking.
	 * @param string $slug    Plugin slug.
	 * @param int    $log_id  Transaction log row id.
	 * @return array<string, mixed>|null Receipt data, as data() returns it.
	 */
	public static function can_view( int $user_id, string $slug, int $log_id ): ?array {
		if ( $user_id <= 0 || null === Registry::instance()->get( $slug ) ) {
			return null;
		}
		$data = self::data( $slug, $log_id );
		if ( null === $data ) {
			return null;
		}

		return ( (int) $data['user_id'] === $user_id || user_can( $user_id, 'manage_options' ) ) ? $data : null;
	}

	/**
	 * Everything a receipt shows, formatted.
	 *
	 * @since 1.9.0
	 * @param string $slug   Plugin slug.
	 * @param int    $log_id Transaction_Log row id.
	 * @return array<string, mixed>|null Null when there is no such order.
	 */
	public static function data( string $slug, int $log_id ): ?array {
		$row = Transaction_Log::find_by_id( $slug, $log_id );
		if ( null === $row ) {
			return null;
		}

		$settings = Checkout_Settings::get( $slug );
		$currency = (string) $row['currency'];
		$money    = static fn ( int $minor ): string => self::format( $minor, $currency );
		$billing  = (array) $row['billing'];
		if ( ! empty( $billing['billing_country'] ) ) {
			$billing['billing_country'] = Countries::label( (string) $billing['billing_country'] );
		}

		return array(
			'number'    => $settings['invoice_prefix'] . $row['id'],
			'date'      => mysql2date( get_option( 'date_format' ), (string) $row['created_at'] ),
			'seller'    => array(
				'name'    => '' !== $settings['seller_name'] ? $settings['seller_name'] : get_bloginfo( 'name' ),
				'address' => $settings['seller_address'],
				'tax_id'  => $settings['seller_tax_id'],
			),
			'billing'   => $billing,
			'credits'   => (int) $row['credits'],
			'subtotal'  => $money( (int) $row['subtotal_cents'] ),
			'discount'  => (int) $row['discount_cents'] > 0 ? $money( (int) $row['discount_cents'] ) : '',
			'coupon'    => (string) $row['coupon'],
			'tax'       => (int) $row['tax_cents'] > 0 ? $money( (int) $row['tax_cents'] ) : '',
			'tax_label' => '' !== $settings['tax_label'] ? $settings['tax_label'] : __( 'Tax', 'wbcom-credits-sdk' ),
			'total'     => $money( (int) $row['amount_cents'] ),
			'refunded'  => (int) $row['refunded_cents'] > 0 ? $money( (int) $row['refunded_cents'] ) : '',
			'gateway'   => (string) $row['gateway'],
			'user_id'   => (int) $row['user_id'],
		);
	}

	/**
	 * Minor units as money in the currency's symbol and decimals.
	 *
	 * @since 1.9.0
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	public static function format( int $minor, string $currency ): string {
		return Currencies::symbol( $currency ) . number_format_i18n( Money::to_major( $minor, $currency ), Money::decimals_for( $currency ) );
	}

	/**
	 * `template_redirect`: serve the printable receipt to its buyer or an admin.
	 *
	 * @deprecated 1.10.0 Serve the receipt from the consumer: guard with
	 *             can_view(), render data() in its own template, and point
	 *             links there with the `wbcom_credits_receipt_url` filter.
	 *             This route and templates/frontend/receipt.php go in 2.0.0.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page gated on ownership below.
		if ( ! isset( $_GET[ self::VAR ] ) ) {
			return;
		}
		$log_id = absint( wp_unslash( $_GET[ self::VAR ] ) );
		$slug   = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
		// phpcs:enable

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$data = self::can_view( get_current_user_id(), $slug, $log_id );
		if ( null === $data ) {
			wp_die( esc_html__( 'Receipt not found.', 'wbcom-credits-sdk' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		Template::get( 'frontend/receipt', array( 'receipt' => $data ), $slug );
		exit;
	}
}
