<?php
/**
 * Server-authoritative pricing resolver.
 *
 * The direct-gateway checkout endpoint must never trust client-supplied
 * `price_cents` — pre-1.3.0 a logged-in user could POST credits=10000 +
 * price_cents=1 and walk away with 10,000 credits for 1¢. This resolver
 * computes the canonical `{credits, price_cents, currency}` tuple from a
 * consumer-registered pricing config and request params, refusing the
 * request when the config is missing or the inputs fall outside bounds.
 *
 * Two modes, both server-authoritative:
 *
 *   1. Pack mode (preferred — matches 1-click hosted-checkout UX):
 *      Consumer registers a `packs` map of {pack_id => {credits, price_cents}}.
 *      Frontend POSTs `pack_id`. SDK looks up the tuple. Client never sees
 *      or controls any number after the button render.
 *
 *   2. Callback mode (for adjustable-quantity flows):
 *      Consumer registers `credits_to_price_cents` callable plus
 *      `min_credits` / `max_credits` bounds. Frontend POSTs `credits`.
 *      SDK enforces bounds, calls the callable, uses the result.
 *
 * Client-supplied `price_cents` in the request body is silently dropped
 * — the SDK never reads it after 1.3.0.
 *
 * @package Wbcom\Credits\Gateways
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

use Wbcom\Credits\Registry;

defined( 'ABSPATH' ) || exit;

final class Pricing {

	/**
	 * Resolve credits + price_cents + currency for a checkout request.
	 *
	 * @param string $slug   Consumer slug (e.g. 'wp-career-board').
	 * @param array  $params Request params — recognised: `pack_id`, `credits`.
	 *                       Any `price_cents` key is ignored.
	 * @return array{credits:int, price_cents:int, currency:string, mode:string, pack_id?:string}
	 * @throws PricingException When pricing is missing/invalid or params fail bounds.
	 */
	public static function resolve( string $slug, array $params ): array {
		$config = Registry::instance()->get( $slug );
		if ( ! is_array( $config ) ) {
			throw new PricingException( 'plugin_not_registered', "Plugin not registered: {$slug}", 404 );
		}

		$pricing = $config['pricing'] ?? null;
		if ( ! is_array( $pricing ) || empty( $pricing ) ) {
			throw new PricingException(
				'pricing_not_configured',
				"Consumer '{$slug}' has not registered server-authoritative pricing. See docs/MIGRATION-1.3.0-pricing.md.",
				503
			);
		}

		$currency = strtoupper( (string) ( $pricing['currency'] ?? 'USD' ) );

		$pack_id_raw = $params['pack_id'] ?? '';
		$pack_id     = is_string( $pack_id_raw ) ? sanitize_key( $pack_id_raw ) : '';

		if ( '' !== $pack_id ) {
			$packs = is_array( $pricing['packs'] ?? null ) ? $pricing['packs'] : array();
			if ( ! isset( $packs[ $pack_id ] ) || ! is_array( $packs[ $pack_id ] ) ) {
				throw new PricingException( 'unknown_pack', "Unknown credit pack '{$pack_id}'.", 404 );
			}

			$pack    = $packs[ $pack_id ];
			$credits = (int) ( $pack['credits'] ?? 0 );
			$cents   = (int) ( $pack['price_cents'] ?? 0 );

			if ( $credits <= 0 || $cents <= 0 ) {
				throw new PricingException( 'invalid_pack', "Pack '{$pack_id}' has invalid credits/price_cents.", 500 );
			}

			return array(
				'credits'     => $credits,
				'price_cents' => $cents,
				'currency'    => $currency,
				'mode'        => 'pack',
				'pack_id'     => $pack_id,
			);
		}

		$credits = isset( $params['credits'] ) ? (int) $params['credits'] : 0;
		if ( $credits <= 0 ) {
			throw new PricingException(
				'missing_input',
				"Request must include either 'pack_id' or 'credits'. Client-supplied 'price_cents' is ignored.",
				400
			);
		}

		$callback = $pricing['credits_to_price_cents'] ?? null;
		if ( ! is_callable( $callback ) ) {
			throw new PricingException(
				'callback_not_configured',
				"Consumer '{$slug}' did not register a 'credits_to_price_cents' callback. Either register one or use pack_id.",
				503
			);
		}

		$min = max( 1, (int) ( $pricing['min_credits'] ?? 1 ) );
		$max = max( $min, (int) ( $pricing['max_credits'] ?? PHP_INT_MAX ) );

		if ( $credits < $min || $credits > $max ) {
			throw new PricingException(
				'credits_out_of_bounds',
				"Credits {$credits} out of bounds [{$min}..{$max}].",
				400
			);
		}

		$cents = (int) call_user_func( $callback, $credits );
		if ( $cents <= 0 ) {
			throw new PricingException(
				'invalid_callback_result',
				"Pricing callback for '{$slug}' returned non-positive price_cents.",
				500
			);
		}

		return array(
			'credits'     => $credits,
			'price_cents' => $cents,
			'currency'    => $currency,
			'mode'        => 'callback',
		);
	}
}

/**
 * Typed exception so callers can map to specific WP_Error codes / HTTP statuses.
 *
 * @since 1.3.0
 */
final class PricingException extends \RuntimeException {

	public function __construct( public string $error_code, string $message, public int $http_status = 400 ) {
		parent::__construct( $message );
	}
}
