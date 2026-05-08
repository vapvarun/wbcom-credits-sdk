<?php
/**
 * Payment gateway interface — direct payment support.
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
 * Most gateways extend {@see Abstract_Gateway} and only implement the
 * provider-specific methods on this interface. The abstract base
 * orchestrates webhook routing, idempotency, amount cross-checks,
 * top-up, refund accounting, and Transaction_Log writes — that logic
 * is identical across providers and shouldn't be re-implemented.
 *
 * Built-in implementations (since 1.2.0):
 * - {@see \Wbcom\Credits\Gateways\Stripe}: Stripe Checkout
 * - {@see \Wbcom\Credits\Gateways\PayPal}: PayPal Orders v2
 *
 * @since 1.0.0
 */
interface GatewayInterface {

	/**
	 * Stable machine identifier (e.g., 'stripe', 'paypal', 'razorpay').
	 *
	 * @since 1.0.0
	 */
	public function get_id(): string;

	/**
	 * Human-readable name shown in admin settings.
	 *
	 * @since 1.0.0
	 */
	public function get_label(): string;

	/**
	 * Whether the gateway has every credential it needs to take a payment.
	 *
	 * @since 1.0.0
	 */
	public function is_available(): bool;

	/**
	 * Settings field definitions consumed by the admin UI helper.
	 *
	 * Each field is `array{key:string, type:string, label:string, options?:array}`.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array;

	/**
	 * Create a checkout session and return the URL the user is redirected to.
	 *
	 * Implementations must register the (slug, session_id, user_id, credits,
	 * amount, currency) tuple with {@see Pending_Checkouts::put()} so the
	 * matching webhook can verify the payment matches the request.
	 *
	 * @since 1.0.0
	 * @since 1.2.1 $return_url parameter added.
	 *
	 * @param string      $slug        Plugin slug.
	 * @param int         $user_id     WordPress user ID.
	 * @param int         $credits     Credits being purchased.
	 * @param int         $price_cents Price in cents (e.g., 999 for $9.99).
	 * @param string      $currency    ISO 4217 currency code.
	 * @param string|null $return_url  Optional. Frontend page the user came
	 *     from. When provided, gateways append `?wbcom_credits=success` /
	 *     `?wbcom_credits=cancel` to it and use as success_url / cancel_url
	 *     overrides. Lets blocks placed on multiple pages bring users back
	 *     to the page they clicked from instead of the saved settings URL.
	 * @return string Hosted checkout URL.
	 *
	 * @throws \RuntimeException When the provider rejects the request.
	 */
	public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD', ?string $return_url = null ): string;

	/**
	 * Verify the webhook signature using raw request body + headers.
	 *
	 * Called by the SDK webhook router before {@see handle_webhook()}.
	 * Returning false yields a 400 response and the payload is never
	 * processed.
	 *
	 * @since 1.2.0
	 *
	 * @param string               $raw_body Raw request body.
	 * @param array<string,string> $headers  Lowercased headers.
	 */
	public function verify_signature( string $raw_body, array $headers ): bool;

	/**
	 * Translate a provider-specific event payload to a normalized DTO.
	 *
	 * Returns null if the event type is not one we handle (the webhook
	 * router will then 200-acknowledge the event without further work).
	 *
	 * @since 1.2.0
	 *
	 * @param array $payload Decoded JSON payload.
	 * @return Gateway_Event|null
	 */
	public function normalize_event( array $payload ): ?Gateway_Event;

	/**
	 * Execute the verified, normalized webhook event end-to-end.
	 *
	 * Default implementation in {@see Abstract_Gateway::handle_webhook()}
	 * orchestrates: idempotency check → cross-check pending checkout → topup
	 * (or refund) → Transaction_Log write → mark processed. Most gateways
	 * never need to override this.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 * @param array  $payload Decoded payload.
	 */
	public function handle_webhook( string $slug, array $payload ): \WP_REST_Response;

	/**
	 * Issue a refund through the provider for a previously completed checkout.
	 *
	 * Both partial and full refunds are supported:
	 *   $amount_cents === null → full refund of remaining capturable amount
	 *   $amount_cents > 0      → partial refund.
	 *
	 * The provider will subsequently send a refund webhook which the SDK
	 * processes to debit the user's credits and append a
	 * {@see Transaction_Log::KIND_REFUND} row.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $slug
	 * @param string   $session_id   Provider session id from the original checkout.
	 * @param int|null $amount_cents Partial amount, or null for full refund.
	 * @return bool True on success (provider accepted the refund).
	 */
	public function refund( string $slug, string $session_id, ?int $amount_cents = null ): bool;
}
