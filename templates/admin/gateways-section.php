<?php
/**
 * Default template — payment-gateway settings sections.
 *
 * Override by copying to:
 *   {theme}/wbcom-credits/{slug}/admin/gateways-section.php
 * or:
 *   {theme}/wbcom-credits/admin/gateways-section.php
 *
 * Flat by design (since 1.8.0): one outer wrapper is the only "card" this
 * template owns. Each gateway is a sub-heading followed directly by its own
 * fields table, both siblings inside that same wrapper — never a card
 * nested inside a card. A consumer that wraps this markup in its own
 * settings-page card gets exactly one visible box, not several nested
 * ones. Consumers style the host page with their own admin tokens; this
 * template intentionally ships no CSS of its own, so it inherits whatever
 * dark-mode / RTL styling the host page already applies.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 * @since   1.8.0 Flattened: the per-gateway <section>/<header> wrapper is
 *                gone, replaced by a sub-heading in the same outer wrapper.
 *
 * @var array  $args      Template args (canonical SDK convention).
 * @var string $args.slug Consuming plugin slug.
 * @var array  $args.gateways Per-gateway descriptors {id, label, available, fields, values, webhook_url, success_default, cancel_default}.
 */

defined( 'ABSPATH' ) || exit;

$slug          = (string) ( $args['slug'] ?? '' );
$gateway_views = (array) ( $args['gateways'] ?? array() );
?>
<div class="wbcom-credits-gateways">
	<?php foreach ( $gateway_views as $gw ) : ?>
		<?php
		$gw_id      = (string) $gw['id'];
		$gw_label   = (string) $gw['label'];
		$available  = (bool) $gw['available'];
		$fields     = (array) $gw['fields'];
		$values     = (array) $gw['values'];
		$webhook    = (string) $gw['webhook_url'];
		$success_d  = (string) $gw['success_default'];
		$cancel_d   = (string) $gw['cancel_default'];
		$status_msg = $available
			? __( 'Configured', 'wbcom-credits-sdk' )
			: __( 'Not configured — enable + add credentials below.', 'wbcom-credits-sdk' );
		?>
		<h3 class="wbcom-credits-gateways__title" id="wbcom-credits-gateway-<?php echo esc_attr( $gw_id ); ?>">
			<?php echo esc_html( $gw_label ); ?>
			<span class="wbcom-credits-gateways__status wbcom-credits-gateways__status--<?php echo $available ? 'on' : 'off'; ?>">
				<?php echo esc_html( $status_msg ); ?>
			</span>
		</h3>

		<table class="form-table wbcom-credits-gateways__fields" role="presentation">
			<tbody>
				<?php foreach ( $fields as $field ) : ?>
					<?php
					$key   = (string) ( $field['key'] ?? '' );
					$ftype = (string) ( $field['type'] ?? 'text' );
					$flbl  = (string) ( $field['label'] ?? $key );
					if ( '' === $key ) {
						continue;
					}

					$current      = $values[ $key ] ?? '';
					$input_name   = sprintf( 'gateway_%s[%s]', $gw_id, $key );
					$input_id     = sprintf( 'gw-%s-%s', $gw_id, $key );
					$is_secret    = in_array( $ftype, array( 'password' ), true );
					$has_value    = '' !== (string) $current;
					$placeholder  = '';

					// Helpful default placeholders for common URL fields.
					if ( 'url' === $ftype && '' === $current ) {
						if ( 'success_url' === $key ) {
							$placeholder = $success_d;
						} elseif ( 'cancel_url' === $key ) {
							$placeholder = $cancel_d;
						}
					}
					?>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $flbl ); ?></label>
						</th>
						<td>
							<?php if ( 'bool' === $ftype ) : ?>
								<?php
								// No repeated caption here: the row's own
								// <th><label> above already names this field
								// (e.g. "Enable Stripe"), so echoing the same
								// text again next to the checkbox is a
								// redundant "Enabled" caption beside a
								// status pill that may say the opposite.
								?>
								<input type="checkbox" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" value="1" <?php checked( ! empty( $current ) ); ?> />

							<?php elseif ( 'select' === $ftype ) : ?>
								<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>">
									<?php foreach ( (array) ( $field['options'] ?? array() ) as $opt_value => $opt_label ) : ?>
										<option value="<?php echo esc_attr( (string) $opt_value ); ?>" <?php selected( (string) $current, (string) $opt_value ); ?>>
											<?php echo esc_html( (string) $opt_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>

							<?php elseif ( $is_secret ) : ?>
								<input
									type="password"
									id="<?php echo esc_attr( $input_id ); ?>"
									name="<?php echo esc_attr( $input_name ); ?>"
									value=""
									class="regular-text"
									autocomplete="new-password"
									<?php if ( $has_value ) : ?>
										placeholder="<?php esc_attr_e( '•••••••• (saved — leave blank to keep)', 'wbcom-credits-sdk' ); ?>"
									<?php endif; ?>
								/>
								<?php if ( $has_value ) : ?>
									<p class="description"><?php esc_html_e( 'A value is already saved. Type a new value to replace it; leave blank to keep the existing one.', 'wbcom-credits-sdk' ); ?></p>
								<?php endif; ?>

							<?php elseif ( 'url' === $ftype ) : ?>
								<input
									type="url"
									id="<?php echo esc_attr( $input_id ); ?>"
									name="<?php echo esc_attr( $input_name ); ?>"
									value="<?php echo esc_attr( (string) $current ); ?>"
									class="regular-text"
									<?php if ( $placeholder ) : ?>
										placeholder="<?php echo esc_attr( $placeholder ); ?>"
									<?php endif; ?>
								/>

							<?php else : ?>
								<input
									type="text"
									id="<?php echo esc_attr( $input_id ); ?>"
									name="<?php echo esc_attr( $input_name ); ?>"
									value="<?php echo esc_attr( (string) $current ); ?>"
									class="regular-text"
								/>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL', 'wbcom-credits-sdk' ); ?></th>
					<td>
						<input
							type="text"
							class="regular-text code"
							readonly
							onclick="this.select();"
							value="<?php echo esc_attr( $webhook ); ?>"
						/>
						<p class="description">
							<?php
							if ( 'stripe' === $gw_id ) {
								esc_html_e( 'Stripe → Developers → Webhooks → Add endpoint. Subscribe to checkout.session.completed and charge.refunded. Then paste the signing secret above.', 'wbcom-credits-sdk' );
							} elseif ( 'paypal' === $gw_id ) {
								esc_html_e( 'PayPal Developer → My Apps & Credentials → Webhooks. Subscribe to CHECKOUT.ORDER.APPROVED and PAYMENT.CAPTURE.REFUNDED. Then paste the webhook ID above.', 'wbcom-credits-sdk' );
							} else {
								esc_html_e( 'Paste this URL into your provider\'s webhook configuration.', 'wbcom-credits-sdk' );
							}
							?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
	<?php endforeach; ?>
</div>
