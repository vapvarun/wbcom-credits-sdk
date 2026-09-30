<?php
/**
 * Reusable admin pack-editor renderer + sanitizer.
 *
 * Drop-in helper any consuming plugin can call to render + save credit
 * "pack" pricing (fixed credits-for-a-price bundles) plus an optional
 * custom-amount config, without rebuilding the same form per plugin.
 * `sanitize()` normalizes POSTed input into the exact `pricing`-shaped
 * array `Pricing::resolve()` consumes (a `packs` map plus the
 * `credits_to_price_cents` callback inputs: rate, min, max).
 *
 * Usage in a consuming plugin:
 *
 *     register_setting( 'my_plugin_options', 'my_plugin_pricing', [
 *         'sanitize_callback' => [ Pack_Admin_Renderer::class, 'sanitize' ],
 *         'default'           => [],
 *     ] );
 *
 *     // Inside a <form action="options.php"> settings page:
 *     Pack_Admin_Renderer::render( 'my_plugin_pricing' );
 *
 * Dependency-free by design: `render()` emits the saved packs plus a
 * handful of blank spare rows so the site owner can add packs by filling
 * blanks — no JS row-cloning required.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Settings UI helper for credit pack pricing.
 *
 * @since 1.3.0
 */
final class Pack_Admin_Renderer {

	/**
	 * Number of blank spare pack rows appended when no `spare_rows` arg is given.
	 *
	 * @since 1.3.0
	 */
	private const DEFAULT_SPARE_ROWS = 3;

	/**
	 * Render the pack-editor fieldset for a consuming plugin's option.
	 *
	 * Reads current values via `get_option( $option_name )` and echoes an
	 * escaped fieldset: a currency field, repeatable pack rows (existing
	 * packs plus blank spares), and a custom-amount group. All field names
	 * are namespaced under `{$option_name}[...]` so the output is ready to
	 * post straight into `options.php` via `register_setting()`.
	 *
	 * @since 1.3.0
	 *
	 * @param string                  $option_name Option name this fieldset reads/writes.
	 * @param array{spare_rows?: int} $args        Optional. `spare_rows` overrides the
	 *                                              number of blank pack rows appended
	 *                                              after existing packs (default 3).
	 * @return void
	 *
	 * @deprecated 1.10.0 Render the pack editor in the consumer; keep calling sanitize(). Removed in 2.0.0. See docs/HEADLESS-PLAN.md.
	 */
	public static function render( string $option_name, array $args = array() ): void {
		$saved = get_option( $option_name, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$currency       = (string) ( $saved['currency'] ?? 'USD' );
		$saved_packs    = (array) ( $saved['packs'] ?? array() );
		$custom_enabled = ! empty( $saved['custom_enabled'] );
		$rate_cents     = (int) ( $saved['rate_cents_per_credit'] ?? 0 );
		$min_credits    = (int) ( $saved['min_credits'] ?? 1 );
		$max_credits    = isset( $saved['max_credits'] ) ? (int) $saved['max_credits'] : 0;

		$spare_rows = isset( $args['spare_rows'] ) ? max( 0, (int) $args['spare_rows'] ) : self::DEFAULT_SPARE_ROWS;

		$rows = array();
		foreach ( $saved_packs as $pack ) {
			$pack        = (array) $pack;
			$credits     = (int) ( $pack['credits'] ?? 0 );
			$price_cents = (int) ( $pack['price_cents'] ?? 0 );

			$rows[] = array(
				'credits' => $credits > 0 ? (string) $credits : '',
				'price'   => $price_cents > 0 ? self::format_major( $price_cents, $currency ) : '',
				'expires' => (int) ( $pack['expires_days'] ?? 0 ) > 0 ? (string) (int) $pack['expires_days'] : '',
			);
		}
		for ( $spare = 0; $spare < $spare_rows; $spare++ ) {
			$rows[] = array(
				'credits' => '',
				'price'   => '',
				'expires' => '',
			);
		}
		$step = 0 === \Wbcom\Credits\Money::decimals_for( $currency ) ? '1' : (string) ( 1 / \Wbcom\Credits\Money::factor_for( $currency ) );
		?>
		<div class="wbcom-credits-packs" data-option="<?php echo esc_attr( $option_name ); ?>">
			<h3 class="wbcom-credits-packs__title"><?php esc_html_e( 'Credit Packs', 'wbcom-credits-sdk' ); ?></h3>

			<table class="form-table wbcom-credits-packs__fields" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $option_name ); ?>-currency">
								<?php esc_html_e( 'Currency', 'wbcom-credits-sdk' ); ?>
							</label>
						</th>
						<td>
							<select
								id="<?php echo esc_attr( $option_name ); ?>-currency"
								name="<?php echo esc_attr( $option_name ); ?>[currency]"
							>
								<?php foreach ( \Wbcom\Credits\Support\Currencies::all() as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $currency, $code ); ?>><?php echo esc_html( $code . ' - ' . $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</tbody>
			</table>

			<table class="widefat wbcom-credits-packs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Credits', 'wbcom-credits-sdk' ); ?></th>
						<th><?php esc_html_e( 'Price', 'wbcom-credits-sdk' ); ?></th>
						<th><?php esc_html_e( 'Credits expire after (days)', 'wbcom-credits-sdk' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $i => $row ) : ?>
						<tr>
							<td>
								<input
									type="number"
									min="0"
									step="1"
									name="<?php echo esc_attr( $option_name ); ?>[packs][<?php echo esc_attr( (string) $i ); ?>][credits]"
									value="<?php echo esc_attr( $row['credits'] ); ?>"
									class="small-text"
								/>
							</td>
							<td>
								<input
									type="number"
									min="0"
									step="<?php echo esc_attr( $step ); ?>"
									name="<?php echo esc_attr( $option_name ); ?>[packs][<?php echo esc_attr( (string) $i ); ?>][price]"
									value="<?php echo esc_attr( $row['price'] ); ?>"
									class="small-text"
								/>
							</td>
							<td>
								<input
									type="number"
									min="0"
									step="1"
									name="<?php echo esc_attr( $option_name ); ?>[packs][<?php echo esc_attr( (string) $i ); ?>][expires_days]"
									value="<?php echo esc_attr( $row['expires'] ); ?>"
									class="small-text"
									placeholder="<?php esc_attr_e( 'Never', 'wbcom-credits-sdk' ); ?>"
								/>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Fill in any blank row to add a pack. Rows left blank are ignored.', 'wbcom-credits-sdk' ); ?>
			</p>

			<h4 class="wbcom-credits-packs__subheading"><?php esc_html_e( 'Custom Amount', 'wbcom-credits-sdk' ); ?></h4>

			<table class="form-table wbcom-credits-packs__fields" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $option_name ); ?>-custom-enabled">
								<?php esc_html_e( 'Allow custom amount', 'wbcom-credits-sdk' ); ?>
							</label>
						</th>
						<td>
							<input
								type="checkbox"
								id="<?php echo esc_attr( $option_name ); ?>-custom-enabled"
								name="<?php echo esc_attr( $option_name ); ?>[custom_enabled]"
								value="1"
								<?php checked( $custom_enabled ); ?>
							/>
							<p class="description"><?php esc_html_e( 'Lets customers enter a custom credit amount instead of picking a pack.', 'wbcom-credits-sdk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $option_name ); ?>-rate-cents">
								<?php esc_html_e( 'Price per credit', 'wbcom-credits-sdk' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								min="0"
								step="<?php echo esc_attr( $step ); ?>"
								id="<?php echo esc_attr( $option_name ); ?>-rate-cents"
								name="<?php echo esc_attr( $option_name ); ?>[rate]"
								value="<?php echo esc_attr( $rate_cents > 0 ? self::format_major( $rate_cents, $currency ) : '' ); ?>"
								class="small-text"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $option_name ); ?>-min-credits">
								<?php esc_html_e( 'Minimum credits', 'wbcom-credits-sdk' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								min="1"
								step="1"
								id="<?php echo esc_attr( $option_name ); ?>-min-credits"
								name="<?php echo esc_attr( $option_name ); ?>[min_credits]"
								value="<?php echo esc_attr( (string) $min_credits ); ?>"
								class="small-text"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $option_name ); ?>-max-credits">
								<?php esc_html_e( 'Maximum credits', 'wbcom-credits-sdk' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								min="0"
								step="1"
								id="<?php echo esc_attr( $option_name ); ?>-max-credits"
								name="<?php echo esc_attr( $option_name ); ?>[max_credits]"
								value="<?php echo esc_attr( $max_credits > 0 ? (string) $max_credits : '' ); ?>"
								class="small-text"
							/>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Sanitize callback compatible with `register_setting()`.
	 *
	 * Normalizes raw POSTed input into the `pricing`-shaped array
	 * `Pricing::resolve()` consumes: `{ currency, packs:{id→{credits,
	 * price_cents}}, custom_enabled, rate_cents_per_credit, min_credits,
	 * max_credits }`. Rows with non-positive credits or price are dropped
	 * (blank spare rows resolve to this and are silently skipped).
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $input Raw POSTed value (array keyed by field name).
	 * @return array{
	 *     currency: string,
	 *     packs: array<string, array{credits: int, price_cents: int, expires_days: int}>,
	 *     custom_enabled: bool,
	 *     rate_cents_per_credit: int,
	 *     min_credits: int,
	 *     max_credits: int
	 * } Normalized pricing config.
	 */
	public static function sanitize( $input ): array {
		$in       = is_array( $input ) ? $input : array();
		$currency = strtoupper( sanitize_text_field( (string) ( $in['currency'] ?? 'USD' ) ) );
		if ( ! array_key_exists( $currency, \Wbcom\Credits\Support\Currencies::all() ) ) {
			$currency = 'USD';
		}
		$packs = array();
		foreach ( (array) ( $in['packs'] ?? array() ) as $i => $row ) {
			$credits = (int) ( $row['credits'] ?? 0 );
			// The currency's own minor units: JPY has none, KWD three.
			$cents = \Wbcom\Credits\Money::to_minor( (float) ( $row['price'] ?? 0 ), $currency );
			if ( $credits > 0 && $cents > 0 ) {
				$packs[ 'pack_' . $i ] = array(
					'credits'      => $credits,
					'price_cents'  => $cents,
					'expires_days' => max( 0, (int) ( $row['expires_days'] ?? 0 ) ),
				);
			}
		}
		$min  = max( 1, (int) ( $in['min_credits'] ?? 1 ) );
		$rate = isset( $in['rate'] )
			? \Wbcom\Credits\Money::to_minor( (float) $in['rate'], $currency )
			: (int) ( $in['rate_cents'] ?? 0 );
		return array(
			'currency'              => $currency,
			'packs'                 => $packs,
			'custom_enabled'        => ! empty( $in['custom_enabled'] ),
			'rate_cents_per_credit' => max( 0, $rate ),
			'min_credits'           => $min,
			'max_credits'           => max( $min, (int) ( $in['max_credits'] ?? PHP_INT_MAX ) ),
		);
	}

	/**
	 * Minor units as an editable price in the currency's own decimals,
	 * without trailing zeros.
	 *
	 * @since 1.9.0
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	private static function format_major( int $minor, string $currency ): string {
		$decimals  = \Wbcom\Credits\Money::decimals_for( $currency );
		$formatted = number_format( \Wbcom\Credits\Money::to_major( $minor, $currency ), $decimals, '.', '' );
		return $decimals > 0 ? rtrim( rtrim( $formatted, '0' ), '.' ) : $formatted;
	}
}
