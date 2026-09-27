<?php
/**
 * Printable receipt for a credit purchase.
 *
 * A standalone document (not rendered inside the theme), so it carries its
 * own small stylesheet. Override in a theme at
 * `wbcom-credits/{slug}/frontend/receipt.php` or `wbcom-credits/frontend/receipt.php`.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 *
 * @var array<string, mixed> $args Template args: `receipt` is Receipt::data().
 */

defined( 'ABSPATH' ) || exit;

$wbcom_r = (array) ( $args['receipt'] ?? array() );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( sprintf( /* translators: %s: receipt number */ __( 'Receipt %s', 'wbcom-credits-sdk' ), $wbcom_r['number'] ) ); ?></title>
	<style>
		:root { color-scheme: light dark; --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --bg: #fff; }
		@media (prefers-color-scheme: dark) { :root { --ink: #f3f4f6; --muted: #9ca3af; --line: #374151; --bg: #111827; } }
		body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
		.receipt { max-width: 720px; margin-inline: auto; padding: 32px 16px; }
		.receipt header { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; border-block-end: 1px solid var(--line); padding-block-end: 16px; }
		.receipt h1 { font-size: 22px; margin: 0 0 4px; }
		.muted { color: var(--muted); }
		.parties { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-block: 24px; }
		.parties h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 0 0 4px; }
		table { width: 100%; border-collapse: collapse; }
		th, td { text-align: start; padding: 8px 0; border-block-end: 1px solid var(--line); }
		td.num, th.num { text-align: end; }
		.total td { font-weight: 700; border-block-end: 0; }
		.actions { margin-block-start: 24px; }
		.actions button { font: inherit; padding: 8px 16px; cursor: pointer; }
		@media print { .actions { display: none; } body { background: #fff; color: #000; } }
	</style>
</head>
<body>
<main class="receipt">
	<header>
		<div>
			<h1><?php echo esc_html( (string) $wbcom_r['seller']['name'] ); ?></h1>
			<?php if ( '' !== $wbcom_r['seller']['address'] ) : ?>
				<div class="muted"><?php echo nl2br( esc_html( (string) $wbcom_r['seller']['address'] ) ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $wbcom_r['seller']['tax_id'] ) : ?>
				<div class="muted"><?php echo esc_html( sprintf( /* translators: %s: seller tax number */ __( 'Tax number: %s', 'wbcom-credits-sdk' ), $wbcom_r['seller']['tax_id'] ) ); ?></div>
			<?php endif; ?>
		</div>
		<div>
			<div><strong><?php esc_html_e( 'Receipt', 'wbcom-credits-sdk' ); ?></strong> <?php echo esc_html( (string) $wbcom_r['number'] ); ?></div>
			<div class="muted"><?php echo esc_html( (string) $wbcom_r['date'] ); ?></div>
		</div>
	</header>

	<section class="parties">
		<div>
			<h2><?php esc_html_e( 'Billed to', 'wbcom-credits-sdk' ); ?></h2>
			<?php
			$wbcom_b    = (array) $wbcom_r['billing'];
			$wbcom_name = trim( ( $wbcom_b['billing_first_name'] ?? '' ) . ' ' . ( $wbcom_b['billing_last_name'] ?? '' ) );
			$wbcom_rows = array_filter(
				array(
					$wbcom_name,
					$wbcom_b['billing_company'] ?? '',
					$wbcom_b['billing_address_1'] ?? '',
					$wbcom_b['billing_address_2'] ?? '',
					trim( ( $wbcom_b['billing_postcode'] ?? '' ) . ' ' . ( $wbcom_b['billing_city'] ?? '' ) ),
					$wbcom_b['billing_state'] ?? '',
					$wbcom_b['billing_country'] ?? '',
					$wbcom_b['billing_email'] ?? '',
				)
			);
			foreach ( $wbcom_rows as $wbcom_line ) {
				echo '<div>' . esc_html( (string) $wbcom_line ) . '</div>';
			}
			if ( ! empty( $wbcom_b['billing_gst'] ) ) {
				echo '<div class="muted">' . esc_html( sprintf( /* translators: %s: buyer tax number */ __( 'Tax number: %s', 'wbcom-credits-sdk' ), $wbcom_b['billing_gst'] ) ) . '</div>';
			}
			?>
		</div>
	</section>

	<table>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Item', 'wbcom-credits-sdk' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Amount', 'wbcom-credits-sdk' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php echo esc_html( sprintf( /* translators: %s: number of credits */ _n( '%s credit', '%s credits', (int) $wbcom_r['credits'], 'wbcom-credits-sdk' ), number_format_i18n( (int) $wbcom_r['credits'] ) ) ); ?></td>
				<td class="num"><?php echo esc_html( (string) $wbcom_r['subtotal'] ); ?></td>
			</tr>
			<?php if ( '' !== $wbcom_r['discount'] ) : ?>
				<tr>
					<td><?php echo esc_html( sprintf( /* translators: %s: coupon code */ __( 'Discount (%s)', 'wbcom-credits-sdk' ), $wbcom_r['coupon'] ) ); ?></td>
					<td class="num">&minus;<?php echo esc_html( (string) $wbcom_r['discount'] ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( '' !== $wbcom_r['tax'] ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $wbcom_r['tax_label'] ); ?></td>
					<td class="num"><?php echo esc_html( (string) $wbcom_r['tax'] ); ?></td>
				</tr>
			<?php endif; ?>
			<tr class="total">
				<td><?php esc_html_e( 'Total paid', 'wbcom-credits-sdk' ); ?></td>
				<td class="num"><?php echo esc_html( (string) $wbcom_r['total'] ); ?></td>
			</tr>
			<?php if ( '' !== $wbcom_r['refunded'] ) : ?>
				<tr>
					<td><?php esc_html_e( 'Refunded', 'wbcom-credits-sdk' ); ?></td>
					<td class="num">&minus;<?php echo esc_html( (string) $wbcom_r['refunded'] ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<p class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'wbcom-credits-sdk' ); ?></button></p>
</main>
</body>
</html>
