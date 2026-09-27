<?php
/**
 * Coupons for credit-pack checkouts.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

use Wbcom\Credits\Money;

defined( 'ABSPATH' ) || exit;

/**
 * One option per slug, `wbcom_credits_coupons_{slug}`: code => { type
 * (percent|fixed), amount (percent, or major units of the pack currency),
 * expires (Y-m-d, inclusive, '' for never), usage_limit (0 for unlimited),
 * active }. Usage is counted from paid checkouts in the Transaction_Log, plus
 * checkouts started within the hold window (usage()), so it can't drift from
 * what was sold or be oversold by buyers checking out at the same time.
 *
 * @since 1.9.0
 */
final class Coupons {

	/**
	 * Blank rows offered for new coupons.
	 *
	 * @var int
	 */
	private const SPARE_ROWS = 3;

	/**
	 * Option name for a slug.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public static function option_name( string $slug ): string {
		return 'wbcom_credits_coupons_' . sanitize_key( $slug );
	}

	/**
	 * All coupons for a slug, keyed by upper-case code.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return array<string, array{type: string, amount: float, expires: string, usage_limit: int, active: bool}>
	 */
	public static function all( string $slug ): array {
		$saved = get_option( self::option_name( $slug ), array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * A coupon that can be used now, or why not.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @param string $code Code as typed.
	 * @return array{code: string, type: string, amount: float}|\WP_Error
	 */
	public static function find( string $slug, string $code ) {
		$code   = strtoupper( trim( $code ) );
		$coupon = self::all( $slug )[ $code ] ?? null;

		if ( ! is_array( $coupon ) || empty( $coupon['active'] ) ) {
			return new \WP_Error( 'coupon_invalid', __( 'That coupon code is not valid.', 'wbcom-credits-sdk' ), array( 'status' => 400 ) );
		}
		if ( '' !== (string) ( $coupon['expires'] ?? '' ) && gmdate( 'Y-m-d' ) > (string) $coupon['expires'] ) {
			return new \WP_Error( 'coupon_expired', __( 'That coupon has expired.', 'wbcom-credits-sdk' ), array( 'status' => 400 ) );
		}
		// The checkout route re-checks this under with_lock() and records the
		// use before releasing it, so two buyers can't both take the last one.
		$limit = (int) ( $coupon['usage_limit'] ?? 0 );
		if ( $limit > 0 && self::usage( $slug, $code ) >= $limit ) {
			return new \WP_Error( 'coupon_used_up', __( 'That coupon has been used up.', 'wbcom-credits-sdk' ), array( 'status' => 400 ) );
		}

		return array(
			'code'   => $code,
			'type'   => 'fixed' === ( $coupon['type'] ?? '' ) ? 'fixed' : 'percent',
			'amount' => (float) ( $coupon['amount'] ?? 0 ),
		);
	}

	/**
	 * The discount a coupon gives on a subtotal, in minor units, never more
	 * than the subtotal.
	 *
	 * @since 1.9.0
	 * @param array{type: string, amount: float} $coupon   Valid coupon.
	 * @param int                                $subtotal Subtotal in minor units.
	 * @param string                             $currency ISO 4217 code.
	 * @return int
	 */
	public static function discount( array $coupon, int $subtotal, string $currency ): int {
		$off = 'fixed' === $coupon['type']
			? Money::to_minor( $coupon['amount'], $currency )
			: (int) floor( $subtotal * min( 100.0, max( 0.0, $coupon['amount'] ) ) / 100 );
		return max( 0, min( $subtotal, $off ) );
	}

	/**
	 * Paid checkouts that used a code.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @param string $code Upper-case code.
	 * @return int
	 */
	public static function usage( string $slug, string $code ): int {
		$paid = Transaction_Log::count_transactions(
			$slug,
			array(
				'kind'   => Transaction_Log::KIND_CHECKOUT,
				'coupon' => $code,
			)
		);

		/**
		 * Seconds an unpaid checkout holds a use of a limited coupon.
		 *
		 * A use is otherwise counted only once paid, so every buyer who
		 * started checkout before the first paid could take the last use.
		 * An abandoned checkout releases its hold after this long. A buyer
		 * who pays later is still credited.
		 *
		 * @since 1.9.2
		 * @param int $seconds Default one hour.
		 */
		$hold = (int) apply_filters( 'wbcom_credits_coupon_hold_seconds', HOUR_IN_SECONDS );

		return $paid + Pending_Checkouts::coupon_holds( $slug, $code, time() - max( 0, $hold ) );
	}

	/**
	 * Run $fn while holding this coupon's lock (checking and recording a use).
	 *
	 * @since 1.9.2
	 * @param string   $slug Plugin slug.
	 * @param string   $code Upper-case code.
	 * @param callable $fn   Work.
	 * @return mixed What $fn returned, or false when the lock timed out.
	 */
	public static function with_lock( string $slug, string $code, callable $fn ): mixed {
		return \Wbcom\Credits\Ledger::with_lock( 'coupon|' . sanitize_key( $slug ) . '|' . strtoupper( $code ), $fn );
	}

	/**
	 * Sanitize the admin table.
	 *
	 * @since 1.9.0
	 * @param mixed $input Raw rows.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( $input ): array {
		$out = array();
		foreach ( (array) ( is_array( $input ) ? ( $input['rows'] ?? array() ) : array() ) as $row ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $row['code'] ?? '' ) ) );
			if ( '' === $code ) {
				continue;
			}
			$expires = (string) ( $row['expires'] ?? '' );
			$out[ $code ] = array(
				'type'        => 'fixed' === ( $row['type'] ?? '' ) ? 'fixed' : 'percent',
				'amount'      => max( 0.0, (float) ( $row['amount'] ?? 0 ) ),
				'expires'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ? $expires : '',
				'usage_limit' => max( 0, (int) ( $row['usage_limit'] ?? 0 ) ),
				'active'      => ! empty( $row['active'] ),
			);
		}
		return $out;
	}

	/**
	 * Render the coupon table (the caller wraps it in its own form/card).
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function render( string $slug ): void {
		$name = self::option_name( $slug );
		$rows = array();
		foreach ( self::all( $slug ) as $code => $c ) {
			$rows[] = array_merge( $c, array( 'code' => $code, 'used' => self::usage( $slug, $code ) ) );
		}
		for ( $i = 0; $i < self::SPARE_ROWS; $i++ ) {
			$rows[] = array( 'code' => '', 'type' => 'percent', 'amount' => '', 'expires' => '', 'usage_limit' => '', 'active' => true, 'used' => null );
		}
		?>
		<table class="widefat wbcom-credits-coupons-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Code', 'wbcom-credits-sdk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Discount', 'wbcom-credits-sdk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'wbcom-credits-sdk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expires', 'wbcom-credits-sdk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Usage limit', 'wbcom-credits-sdk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Used', 'wbcom-credits-sdk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Active', 'wbcom-credits-sdk' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $i => $r ) : ?>
					<?php $base = $name . '[rows][' . $i . ']'; ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $base . '[code]' ); ?>" value="<?php echo esc_attr( (string) $r['code'] ); ?>" class="regular-text" aria-label="<?php esc_attr_e( 'Code', 'wbcom-credits-sdk' ); ?>"></td>
						<td>
							<select name="<?php echo esc_attr( $base . '[type]' ); ?>" aria-label="<?php esc_attr_e( 'Discount', 'wbcom-credits-sdk' ); ?>">
								<option value="percent" <?php selected( $r['type'], 'percent' ); ?>><?php esc_html_e( 'Percent off', 'wbcom-credits-sdk' ); ?></option>
								<option value="fixed" <?php selected( $r['type'], 'fixed' ); ?>><?php esc_html_e( 'Amount off', 'wbcom-credits-sdk' ); ?></option>
							</select>
						</td>
						<td><input type="number" min="0" step="any" name="<?php echo esc_attr( $base . '[amount]' ); ?>" value="<?php echo esc_attr( (string) $r['amount'] ); ?>" class="small-text" aria-label="<?php esc_attr_e( 'Amount', 'wbcom-credits-sdk' ); ?>"></td>
						<td><input type="date" name="<?php echo esc_attr( $base . '[expires]' ); ?>" value="<?php echo esc_attr( (string) $r['expires'] ); ?>" aria-label="<?php esc_attr_e( 'Expires', 'wbcom-credits-sdk' ); ?>"></td>
						<td><input type="number" min="0" step="1" name="<?php echo esc_attr( $base . '[usage_limit]' ); ?>" value="<?php echo esc_attr( (string) ( $r['usage_limit'] ?: '' ) ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'No limit', 'wbcom-credits-sdk' ); ?>" aria-label="<?php esc_attr_e( 'Usage limit', 'wbcom-credits-sdk' ); ?>"></td>
						<td><?php echo null === $r['used'] ? '' : esc_html( number_format_i18n( (int) $r['used'] ) ); ?></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $base . '[active]' ); ?>" value="1" <?php checked( ! empty( $r['active'] ) ); ?> aria-label="<?php esc_attr_e( 'Active', 'wbcom-credits-sdk' ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Fill in a blank row to add a coupon; clear its code to delete it. Amount off is in the pack currency.', 'wbcom-credits-sdk' ); ?></p>
		<?php
	}
}
