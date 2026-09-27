<?php
/**
 * Checkout settings: billing mode, tax, and who the receipts come from.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * One option per slug, `wbcom_credits_checkout_{slug}`. The consuming plugin
 * registers it (register_setting with {@see self::sanitize()}) and renders
 * the fields with {@see self::render()} inside its own settings card.
 *
 * @since 1.9.0
 */
final class Checkout_Settings {

	/**
	 * Defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'billing_mode'   => 'basic',
		'tax_rate'       => 0.0,
		'tax_label'      => '',
		'seller_name'    => '',
		'seller_address' => '',
		'seller_tax_id'  => '',
		'invoice_prefix' => 'INV-',
	);

	/**
	 * Option name for a slug.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public static function option_name( string $slug ): string {
		return 'wbcom_credits_checkout_' . sanitize_key( $slug );
	}

	/**
	 * Settings for a slug, defaults filled in.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return array{billing_mode: string, tax_rate: float, tax_label: string, seller_name: string, seller_address: string, seller_tax_id: string, invoice_prefix: string}
	 */
	public static function get( string $slug ): array {
		$saved = get_option( self::option_name( $slug ), array() );
		$s     = array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
		$s['tax_rate'] = (float) $s['tax_rate'];

		/**
		 * Filter a slug's checkout settings.
		 *
		 * @since 1.9.0
		 *
		 * @param array  $settings Settings.
		 * @param string $slug     Plugin slug.
		 */
		return (array) apply_filters( 'wbcom_credits_checkout_settings', $s, $slug );
	}

	/**
	 * Sanitize a submitted settings array.
	 *
	 * @since 1.9.0
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$in = is_array( $input ) ? $input : array();
		return array(
			'billing_mode'   => 'full' === ( $in['billing_mode'] ?? '' ) ? 'full' : 'basic',
			'tax_rate'       => round( min( 100.0, max( 0.0, (float) ( $in['tax_rate'] ?? 0 ) ) ), 3 ),
			'tax_label'      => sanitize_text_field( (string) ( $in['tax_label'] ?? '' ) ),
			'seller_name'    => sanitize_text_field( (string) ( $in['seller_name'] ?? '' ) ),
			'seller_address' => sanitize_textarea_field( (string) ( $in['seller_address'] ?? '' ) ),
			'seller_tax_id'  => sanitize_text_field( (string) ( $in['seller_tax_id'] ?? '' ) ),
			'invoice_prefix' => sanitize_text_field( (string) ( $in['invoice_prefix'] ?? 'INV-' ) ),
		);
	}

	/**
	 * Render the settings rows (the caller wraps them in its own form/card).
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function render( string $slug ): void {
		$name = self::option_name( $slug );
		$s    = self::get( $slug );
		$row  = static function ( string $key, string $label, string $control, string $help = '' ) use ( $name ): void {
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $name . '-' . $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts below. ?>
					<?php if ( '' !== $help ) : ?>
						<p class="description"><?php echo esc_html( $help ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		};
		$input = static function ( string $key, string $value, string $type = 'text', string $class = 'regular-text', string $extra = '' ) use ( $name ): string {
			return sprintf(
				'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s" %6$s>',
				esc_attr( $type ),
				esc_attr( $name . '-' . $key ),
				esc_attr( $name . '[' . $key . ']' ),
				esc_attr( $value ),
				esc_attr( $class ),
				$extra
			);
		};
		?>
		<table class="form-table wbcom-credits-checkout-settings" role="presentation">
			<tbody>
				<?php
				$row(
					'billing_mode',
					__( 'Billing details', 'wbcom-credits-sdk' ),
					sprintf(
						'<select id="%1$s" name="%2$s"><option value="basic" %3$s>%4$s</option><option value="full" %5$s>%6$s</option></select>',
						esc_attr( $name . '-billing_mode' ),
						esc_attr( $name . '[billing_mode]' ),
						selected( $s['billing_mode'], 'basic', false ),
						esc_html__( 'Name, email and country', 'wbcom-credits-sdk' ),
						selected( $s['billing_mode'], 'full', false ),
						esc_html__( 'Full postal address (for invoices)', 'wbcom-credits-sdk' )
					),
					__( 'What buyers enter before paying. Company and VAT / GST number are always optional.', 'wbcom-credits-sdk' )
				);
				$row( 'tax_rate', __( 'Tax rate (%)', 'wbcom-credits-sdk' ), $input( 'tax_rate', (string) $s['tax_rate'], 'number', 'small-text', 'min="0" max="100" step="0.001"' ), __( 'Added on top of the pack price at checkout. 0 for no tax.', 'wbcom-credits-sdk' ) );
				$row( 'tax_label', __( 'Tax label', 'wbcom-credits-sdk' ), $input( 'tax_label', $s['tax_label'] ), __( 'Shown on receipts, e.g. VAT or GST. Leave blank for "Tax".', 'wbcom-credits-sdk' ) );
				$row( 'seller_name', __( 'Business name', 'wbcom-credits-sdk' ), $input( 'seller_name', $s['seller_name'] ), __( 'Shown at the top of receipts. Leave blank for the site name.', 'wbcom-credits-sdk' ) );
				$row(
					'seller_address',
					__( 'Business address', 'wbcom-credits-sdk' ),
					sprintf( '<textarea id="%1$s" name="%2$s" rows="3" class="large-text">%3$s</textarea>', esc_attr( $name . '-seller_address' ), esc_attr( $name . '[seller_address]' ), esc_textarea( $s['seller_address'] ) )
				);
				$row( 'seller_tax_id', __( 'Your VAT / GST number', 'wbcom-credits-sdk' ), $input( 'seller_tax_id', $s['seller_tax_id'] ) );
				$row( 'invoice_prefix', __( 'Receipt number prefix', 'wbcom-credits-sdk' ), $input( 'invoice_prefix', $s['invoice_prefix'], 'text', 'small-text' ) );
				?>
			</tbody>
		</table>
		<?php
	}
}
