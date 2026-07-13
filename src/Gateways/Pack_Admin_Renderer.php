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
				'price'   => $price_cents > 0 ? self::format_dollars( $price_cents ) : '',
			);
		}
		for ( $spare = 0; $spare < $spare_rows; $spare++ ) {
			$rows[] = array(
				'credits' => '',
				'price'   => '',
			);
		}
		?>
		<div class="wbcom-credits-packs" data-option="<?php echo esc_attr( $option_name ); ?>">
			<fieldset>
				<legend><?php esc_html_e( 'Credit Packs', 'wbcom-credits-sdk' ); ?></legend>

				<p class="wbcom-credits-packs-field">
					<label for="<?php echo esc_attr( $option_name ); ?>-currency">
						<?php esc_html_e( 'Currency', 'wbcom-credits-sdk' ); ?>
					</label>
					<input
						type="text"
						id="<?php echo esc_attr( $option_name ); ?>-currency"
						name="<?php echo esc_attr( $option_name ); ?>[currency]"
						value="<?php echo esc_attr( $currency ); ?>"
						class="small-text"
						maxlength="3"
					/>
				</p>

				<table class="widefat wbcom-credits-packs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Credits', 'wbcom-credits-sdk' ); ?></th>
							<th><?php esc_html_e( 'Price', 'wbcom-credits-sdk' ); ?></th>
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
										step="0.01"
										name="<?php echo esc_attr( $option_name ); ?>[packs][<?php echo esc_attr( (string) $i ); ?>][price]"
										value="<?php echo esc_attr( $row['price'] ); ?>"
										class="small-text"
									/>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Fill in any blank row to add a pack. Rows left blank are ignored.', 'wbcom-credits-sdk' ); ?>
				</p>

				<h4><?php esc_html_e( 'Custom Amount', 'wbcom-credits-sdk' ); ?></h4>

				<p class="wbcom-credits-packs-field">
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $option_name ); ?>[custom_enabled]"
							value="1"
							<?php checked( $custom_enabled ); ?>
						/>
						<?php esc_html_e( 'Allow customers to enter a custom credit amount', 'wbcom-credits-sdk' ); ?>
					</label>
				</p>

				<p class="wbcom-credits-packs-field">
					<label for="<?php echo esc_attr( $option_name ); ?>-rate-cents">
						<?php esc_html_e( 'Rate (cents per credit)', 'wbcom-credits-sdk' ); ?>
					</label>
					<input
						type="number"
						min="0"
						step="1"
						id="<?php echo esc_attr( $option_name ); ?>-rate-cents"
						name="<?php echo esc_attr( $option_name ); ?>[rate_cents]"
						value="<?php echo esc_attr( (string) $rate_cents ); ?>"
						class="small-text"
					/>
				</p>

				<p class="wbcom-credits-packs-field">
					<label for="<?php echo esc_attr( $option_name ); ?>-min-credits">
						<?php esc_html_e( 'Minimum credits', 'wbcom-credits-sdk' ); ?>
					</label>
					<input
						type="number"
						min="1"
						step="1"
						id="<?php echo esc_attr( $option_name ); ?>-min-credits"
						name="<?php echo esc_attr( $option_name ); ?>[min_credits]"
						value="<?php echo esc_attr( (string) $min_credits ); ?>"
						class="small-text"
					/>
				</p>

				<p class="wbcom-credits-packs-field">
					<label for="<?php echo esc_attr( $option_name ); ?>-max-credits">
						<?php esc_html_e( 'Maximum credits', 'wbcom-credits-sdk' ); ?>
					</label>
					<input
						type="number"
						min="0"
						step="1"
						id="<?php echo esc_attr( $option_name ); ?>-max-credits"
						name="<?php echo esc_attr( $option_name ); ?>[max_credits]"
						value="<?php echo esc_attr( $max_credits > 0 ? (string) $max_credits : '' ); ?>"
						class="small-text"
					/>
				</p>
			</fieldset>
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
	 *     packs: array<string, array{credits: int, price_cents: int}>,
	 *     custom_enabled: bool,
	 *     rate_cents_per_credit: int,
	 *     min_credits: int,
	 *     max_credits: int
	 * } Normalized pricing config.
	 */
	public static function sanitize( $input ): array {
		$in    = is_array( $input ) ? $input : array();
		$packs = array();
		foreach ( (array) ( $in['packs'] ?? array() ) as $i => $row ) {
			$credits = (int) ( $row['credits'] ?? 0 );
			$cents   = (int) round( (float) ( $row['price'] ?? 0 ) * 100 );
			if ( $credits > 0 && $cents > 0 ) {
				$packs[ 'pack_' . $i ] = array(
					'credits'     => $credits,
					'price_cents' => $cents,
				);
			}
		}
		$min = max( 1, (int) ( $in['min_credits'] ?? 1 ) );
		return array(
			'currency'              => strtoupper( sanitize_text_field( (string) ( $in['currency'] ?? 'USD' ) ) ),
			'packs'                 => $packs,
			'custom_enabled'        => ! empty( $in['custom_enabled'] ),
			'rate_cents_per_credit' => max( 0, (int) ( $in['rate_cents'] ?? 0 ) ),
			'min_credits'           => $min,
			'max_credits'           => max( $min, (int) ( $in['max_credits'] ?? PHP_INT_MAX ) ),
		);
	}

	/**
	 * Format a cents amount as a trimmed dollar string for display in a
	 * `step="0.01"` number input (e.g. 2900 → "29", 2999 → "29.99").
	 *
	 * @since 1.3.0
	 *
	 * @param int $price_cents Price in cents. Expected > 0.
	 * @return string Dollar amount with no unnecessary trailing zeros.
	 */
	private static function format_dollars( int $price_cents ): string {
		$formatted = number_format( $price_cents / 100, 2, '.', '' );
		$formatted = rtrim( $formatted, '0' );
		return rtrim( $formatted, '.' );
	}
}
