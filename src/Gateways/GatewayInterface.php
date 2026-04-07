<?php
/**
 * Payment gateway interface — future direct payment support.
 *
 * @package Wbcom\Credits
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for direct payment gateways (Stripe, PayPal, Razorpay, etc.).
 *
 * Implementing this interface allows the SDK to process credit purchases
 * directly without requiring WooCommerce or a membership plugin.
 *
 * Future implementations:
 * - StripeGateway:   Stripe Checkout → webhook → topup
 * - PayPalGateway:   PayPal Orders API → webhook → topup
 * - RazorpayGateway: Razorpay Orders → webhook → topup
 *
 * @since 1.0.0
 */
interface GatewayInterface {

	/**
	 * Unique gateway identifier (e.g., 'stripe', 'paypal', 'razorpay').
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Display name for admin settings.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Whether the gateway is configured and ready (API keys set, etc.).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Create a checkout session and return the checkout URL.
	 *
	 * The user is redirected to this URL to complete payment.
	 * On success, the gateway calls Credits::topup() via webhook.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug        Plugin slug (scopes the topup).
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $credits     Number of credits to purchase.
	 * @param int    $price_cents Price in cents (e.g., 999 = $9.99).
	 * @param string $currency    ISO 4217 currency code (default 'USD').
	 * @return string Checkout URL to redirect the user to.
	 */
	public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD' ): string;

	/**
	 * Handle an incoming webhook payload from the payment provider.
	 *
	 * Verifies signature, extracts payment data, and calls Credits::topup()
	 * on successful payment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param array  $payload Raw webhook data.
	 * @return void
	 */
	public function handle_webhook( string $slug, array $payload ): void;

	/**
	 * Return admin settings fields for this gateway.
	 *
	 * @since 1.0.0
	 * @return array Array of settings field definitions.
	 */
	public function get_settings_fields(): array;
}
