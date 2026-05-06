<?php
/**
 * Gateway_Event — provider-agnostic DTO returned by gateway::normalize_event().
 *
 * Each provider speaks its own event vocabulary (Stripe's
 * `checkout.session.completed`, PayPal's `PAYMENT.CAPTURE.COMPLETED`,
 * Razorpay's `payment.captured`, etc.). Translating those to a single
 * vocabulary the AbstractGateway orchestrator can act on keeps every
 * provider's webhook handler tiny and the orchestration logic shared.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized payment event.
 *
 * @since 1.2.0
 */
final class Gateway_Event {

	public const TYPE_CHECKOUT_COMPLETED = 'checkout.completed';
	public const TYPE_REFUND             = 'refund';

	/**
	 * @param string $type           One of TYPE_* constants.
	 * @param string $event_id       Provider event id (used for idempotency).
	 * @param string $session_id     Original checkout/order id (links checkout → refund).
	 * @param int    $amount_cents   For checkout: total paid; for refund: amount refunded.
	 * @param string $currency       ISO 4217.
	 * @param array  $raw            Original payload, kept for logging.
	 */
	public function __construct(
		public string $type,
		public string $event_id,
		public string $session_id,
		public int $amount_cents,
		public string $currency,
		public array $raw = array()
	) {}
}
