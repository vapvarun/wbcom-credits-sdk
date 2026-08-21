<?php
/**
 * Main public API — static facade for all credit operations.
 *
 * @package Wbcom\Credits
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Static API for credit operations scoped per plugin slug.
 *
 * Usage: \Wbcom\Credits\Credits::get_balance( 'my-plugin', $user_id )
 *
 * @since 1.0.0
 */
final class Credits {

	/**
	 * Per-request balance cache to avoid repeated DB queries.
	 *
	 * @var array<string, array<int, int>>
	 */
	private static array $balance_cache = array();

	// -------------------------------------------------------------------------
	// Read operations
	// -------------------------------------------------------------------------

	/**
	 * Get the current credit balance for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @return int Balance.
	 */
	public static function get_balance( string $slug, int $user_id ): int {
		if ( isset( self::$balance_cache[ $slug ][ $user_id ] ) ) {
			return self::$balance_cache[ $slug ][ $user_id ];
		}

		$prefix  = self::get_prefix( $slug );
		$balance = Ledger::get_balance( $prefix, $user_id );

		/**
		 * Filter the credit balance for a user.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $balance Current balance.
		 * @param string $slug    Plugin slug.
		 * @param int    $user_id WordPress user ID.
		 */
		$balance = (int) apply_filters( 'wbcom_credits_balance', $balance, $slug, $user_id );

		self::$balance_cache[ $slug ][ $user_id ] = $balance;

		return $balance;
	}

	/**
	 * Get recent ledger entries for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $limit   Max rows.
	 * @param int    $offset  Pagination offset.
	 * @return array Ledger rows.
	 */
	public static function get_ledger( string $slug, int $user_id, int $limit = 50, int $offset = 0 ): array {
		return Ledger::get_history( self::get_prefix( $slug ), $user_id, $limit, $offset );
	}

	/**
	 * Check if credits are enabled for a plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Plugin slug.
	 * @return bool True if the plugin is registered and credits are active.
	 */
	public static function is_enabled( string $slug ): bool {
		$config = Registry::instance()->get( $slug );
		if ( null === $config ) {
			return false;
		}

		/**
		 * Filter whether credits are enabled for a plugin.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $enabled Whether credits are enabled.
		 * @param string $slug    Plugin slug.
		 */
		return (bool) apply_filters( 'wbcom_credits_enabled', true, $slug );
	}

	// -------------------------------------------------------------------------
	// Write operations (append-only ledger)
	// -------------------------------------------------------------------------

	/**
	 * Add credits to a user's balance.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $amount  Positive credits to add.
	 * @param string $note    Human-readable note.
	 * @return int|false Inserted row ID or false.
	 */
	public static function topup( string $slug, int $user_id, int $amount, string $note = '' ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$result = Ledger::insert( self::get_prefix( $slug ), $user_id, 'topup', abs( $amount ), 0, $note );

		if ( $result ) {
			/**
			 * Fires after credits are topped up.
			 *
			 * @since 1.0.0
			 *
			 * @param string $slug    Plugin slug.
			 * @param int    $user_id WordPress user ID.
			 * @param int    $amount  Credits added.
			 * @param string $note    Description.
			 */
			do_action( 'wbcom_credits_topped_up', $slug, $user_id, $amount, $note );
		}

		return $result;
	}

	/**
	 * Place a hold (reserve credits) on an item.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $amount  Credits to reserve.
	 * @param int    $item_id Associated item ID.
	 * @param string $note    Description.
	 * @return int|false Inserted row ID or false.
	 */
	public static function hold( string $slug, int $user_id, int $amount, int $item_id, string $note = '' ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$note   = $note ?: 'Credits held';
		$result = Ledger::insert( self::get_prefix( $slug ), $user_id, 'hold', -abs( $amount ), $item_id, $note );

		if ( $result ) {
			/**
			 * Fires after credits are held.
			 *
			 * @since 1.0.0
			 *
			 * @param string $slug    Plugin slug.
			 * @param int    $user_id WordPress user ID.
			 * @param int    $amount  Credits held.
			 * @param int    $item_id Item ID.
			 */
			do_action( 'wbcom_credits_held', $slug, $user_id, $amount, $item_id );

			// Check low balance threshold.
			self::maybe_fire_low_balance( $slug, $user_id );
		}

		return $result;
	}

	/**
	 * Convert an existing hold into a permanent deduction.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $amount  Credit cost.
	 * @param int    $item_id Associated item ID.
	 * @param string $note    Description.
	 * @return bool True on success.
	 */
	public static function deduct( string $slug, int $user_id, int $amount, int $item_id, string $note = '' ): bool {
		self::invalidate_cache( $slug, $user_id );

		$note   = $note ?: 'Credits deducted';
		$result = Ledger::deduct_with_hold_release( self::get_prefix( $slug ), $user_id, abs( $amount ), $item_id, $note );

		if ( $result ) {
			/**
			 * Fires after credits are deducted.
			 *
			 * @since 1.0.0
			 *
			 * @param string $slug    Plugin slug.
			 * @param int    $user_id WordPress user ID.
			 * @param int    $amount  Credits deducted.
			 * @param int    $item_id Item ID.
			 */
			do_action( 'wbcom_credits_deducted', $slug, $user_id, $amount, $item_id );
		}

		return $result;
	}

	/**
	 * Refund held credits.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $amount  Credits to return.
	 * @param int    $item_id Associated item ID.
	 * @param string $note    Description.
	 * @return int|false Inserted row ID or false.
	 */
	public static function refund( string $slug, int $user_id, int $amount, int $item_id, string $note = '' ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$note = $note ?: 'Credits refunded';
		$result = Ledger::insert( self::get_prefix( $slug ), $user_id, 'refund', abs( $amount ), $item_id, $note );

		if ( $result ) {
			$context = array(
				'item_id'   => $item_id,
				'ledger_id' => (int) $result,
				'note'      => $note,
				'reason'    => 'hold_refund',
			);
			/**
			 * Fires after credits are refunded.
			 *
			 * Signature (since 1.4.0): ($slug, $user_id, $amount, $context). The
			 * 3rd arg is the REFUNDED CREDIT COUNT (a positive int); the original
			 * `$item_id` now lives in $context['item_id']. The 4th arg is additive
			 * (existing 3-arg listeners keep working), but the 3rd arg changed
			 * meaning from item_id to the credit amount so the contract is
			 * consistent with the gateway-initiated refund path.
			 *
			 * @since 1.0.0
			 * @since 1.4.0 3rd arg is the refunded credit amount; 4th arg ($context) added.
			 *
			 * @param string               $slug    Plugin slug.
			 * @param int                  $user_id WordPress user ID.
			 * @param int                  $amount  Credits refunded (positive int).
			 * @param array<string, mixed> $context Linkage context: item_id, ledger_id, note, reason.
			 */
			do_action( 'wbcom_credits_refunded', $slug, $user_id, abs( $amount ), $context );
		}

		return $result;
	}

	/**
	 * Cancel an unconsumed hold (physical delete).
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $item_id Associated item ID.
	 * @return void
	 */
	public static function cancel_hold( string $slug, int $user_id, int $item_id ): void {
		self::invalidate_cache( $slug, $user_id );
		Ledger::cancel_hold( self::get_prefix( $slug ), $user_id, $item_id );
	}

	/**
	 * Admin adjustment — topup or deduct without hold lifecycle.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $amount  Signed integer (positive = add, negative = remove).
	 * @param string $note    Admin note.
	 * @return int|false Inserted row ID or false.
	 */
	public static function adjust( string $slug, int $user_id, int $amount, string $note = '' ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$entry_type = $amount >= 0 ? 'topup' : 'deduction';
		$note       = $note ?: 'Admin adjustment';

		return Ledger::insert( self::get_prefix( $slug ), $user_id, $entry_type, $amount, 0, $note );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the cost for a consumer item.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug        Plugin slug.
	 * @param string $consumer_id Consumer ID, e.g. 'job_post'.
	 * @param int    $item_id     Specific item ID for dynamic cost.
	 * @return int Credit cost.
	 */
	public static function get_cost( string $slug, string $consumer_id, int $item_id = 0 ): int {
		$config = Registry::instance()->get( $slug );
		if ( null === $config ) {
			return 0;
		}

		$cost = 0;
		foreach ( $config['consumers'] as $consumer ) {
			if ( ( $consumer['id'] ?? '' ) === $consumer_id ) {
				$cost = is_callable( $consumer['cost'] ?? 0 ) ? (int) call_user_func( $consumer['cost'], $item_id ) : (int) ( $consumer['cost'] ?? 0 );
				break;
			}
		}

		/**
		 * Filter the credit cost for an item.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $cost        Credit cost.
		 * @param string $slug        Plugin slug.
		 * @param string $consumer_id Consumer ID.
		 * @param int    $item_id     Item ID.
		 */
		return (int) apply_filters( 'wbcom_credits_cost', $cost, $slug, $consumer_id, $item_id );
	}

	/**
	 * Get the credit purchase URL for a plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Plugin slug.
	 * @return string Purchase URL.
	 */
	public static function get_purchase_url( string $slug ): string {
		$config = Registry::instance()->get( $slug );
		$url    = $config['settings']['purchase_url'] ?? '';

		/**
		 * Filter the credit purchase URL.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url  Purchase URL.
		 * @param string $slug Plugin slug.
		 */
		return (string) apply_filters( 'wbcom_credits_purchase_url', $url, $slug );
	}

	/**
	 * Cancel a SPECIFIC unconsumed hold by the ledger id hold()/hold_money() returned.
	 *
	 * Prefer this over cancel_hold() whenever more than one hold can share an
	 * item_id over the item's lifetime (e.g. multiple plan-activation attempts on
	 * one listing, or sibling need-responses keyed on one need_id). cancel_hold()
	 * deletes ALL 'hold' rows for the item_id, which — because a committed
	 * deduct_with_hold_release() leaves its 'hold' row in place — can delete a
	 * previously-committed attempt's hold and silently reverse that charge. Passing
	 * the exact id returned when the reservation was placed only ever removes the
	 * still-unconsumed hold.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $hold_id Ledger row id returned by hold()/hold_money().
	 * @return void
	 */
	public static function cancel_hold_by_id( string $slug, int $user_id, int $hold_id ): void {
		self::invalidate_cache( $slug, $user_id );
		Ledger::cancel_hold_by_id( self::get_prefix( $slug ), $user_id, $hold_id );
	}

	/**
	 * Which routes a member could actually use to buy credits on this site.
	 *
	 * THE answer to "can a member buy credits here?". Consumers gate their
	 * member-facing credit UI on this instead of assembling their own answer.
	 *
	 * It exists because the SDK previously exposed only the primitives -
	 * `Gateway_Registry::get_available()`, `AdapterRegistry`, the
	 * `{slug}_credit_mappings` option, `get_purchase_url()` - and left the
	 * composite to each consumer. Consumers duly wrote one each, from
	 * different subsets, at different times, and they disagreed on real sites:
	 * one counted mappings but not gateways, another gateways but not
	 * mappings. The narrowest answer is the one that hides UI, so members who
	 * could genuinely buy credits were shown nothing. See issue #7.
	 *
	 * Returning the ROUTES rather than a bare boolean is deliberate: a
	 * consumer telling an owner what to fix needs to distinguish "no gateway"
	 * from "no mapping", and a bare true/false cannot.
	 *
	 * @since 1.6.0
	 *
	 * @param string $slug Consumer plugin slug.
	 * @return array<string, bool> Route => whether it is live. Keys:
	 *                             `gateway`, `mapping`, `external_url`.
	 */
	public static function purchase_paths( string $slug ): array {
		$paths = array(
			'gateway'      => false,
			'mapping'      => false,
			'external_url' => false,
		);

		// A gateway that is enabled AND credentialed - the registry decides.
		$paths['gateway'] = ! empty( Gateways\Gateway_Registry::for_slug( $slug )->get_available() );

		/*
		 * A mapping whose adapter is actually available.
		 *
		 * BOTH halves are required and this is the part consumers kept getting
		 * wrong by hand: a mapping to a WooCommerce product grants nothing
		 * when WooCommerce is inactive, and an active WooCommerce with no
		 * mapping sells nothing. Availability is the ADAPTER's own answer, so
		 * an adapter added to the SDK later is counted with no consumer edit -
		 * which is exactly what a hardcoded consumer-side list cannot do.
		 */
		$registry = new Adapters\AdapterRegistry( $slug, self::get_prefix( $slug ) );
		$mappings = get_option( $slug . '_credit_mappings', array() );

		if ( is_array( $mappings ) ) {
			foreach ( $mappings as $adapter_id => $mapping ) {
				// Accept both storage shapes lookup_credits() accepts: a flat
				// list of rows carrying an `adapter` key, and the nested
				// adapter_id => [ item => credits ] map.
				$id = is_array( $mapping ) && isset( $mapping['adapter'] )
					? (string) $mapping['adapter']
					: (string) $adapter_id;

				if ( '' === $id || is_numeric( $id ) && ! is_array( $mapping ) ) {
					continue;
				}

				$adapter = $registry->get( $id );

				if ( $adapter && $adapter->is_available() ) {
					$paths['mapping'] = true;
					break;
				}
			}
		}

		/*
		 * An explicit purchase URL that leaves this site.
		 *
		 * A SAME-site URL is not counted on its own: it is typically the
		 * auto-set link to the consumer's own credit-packs screen, which
		 * without a gateway is precisely the dead end this check exists to
		 * prevent a member being sent to.
		 */
		$url = trim( self::get_purchase_url( $slug ) );

		if ( '' !== $url ) {
			$url_host  = (string) wp_parse_url( $url, PHP_URL_HOST );
			$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

			if ( '' !== $url_host && $url_host !== $site_host ) {
				$paths['external_url'] = true;
			}
		}

		/**
		 * Filter the live credit purchase routes.
		 *
		 * Consumers add their OWN routes here - a plugin that sells credit
		 * packs as its own products contributes that as a further key. Add a
		 * key rather than replacing the array, so no route is lost.
		 *
		 * @since 1.6.0
		 *
		 * @param array<string, bool> $paths Route => whether it is live.
		 * @param string              $slug  Consumer plugin slug.
		 */
		$paths = (array) apply_filters( 'wbcom_credits_purchase_paths', $paths, $slug );

		return array_map( 'boolval', $paths );
	}

	/**
	 * Whether ANY real purchase route is live.
	 *
	 * Convenience over `purchase_paths()` for the common gate. Prefer
	 * `purchase_paths()` when you need to tell an owner what to fix.
	 *
	 * @since 1.6.0
	 *
	 * @param string $slug Consumer plugin slug.
	 * @return bool
	 */
	public static function can_purchase( string $slug ): bool {
		return in_array( true, self::purchase_paths( $slug ), true );
	}

	// -------------------------------------------------------------------------
	// Money-denominated helpers
	//
	// For consumers whose credits ARE a currency amount. They register a
	// `money` config once — `'money' => array( 'currency' => 'USD' )` (a code
	// or a callable) — and then use these variants, which convert major-unit
	// amounts to the ledger's integer minor units via {@see Money} at a single
	// enforced boundary. This is what stops a money consumer from mixing minor
	// and major units across its several entry points (admin add, webhook,
	// adapters), which silently corrupts a balance. Token consumers ignore all
	// of this and keep using the integer topup()/deduct()/refund() directly.
	// -------------------------------------------------------------------------

	/**
	 * Whether a consumer's ledger is money-denominated (registered `money`).
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug Plugin slug.
	 * @return bool
	 */
	public static function is_money( string $slug ): bool {
		$config = Registry::instance()->get( $slug );
		return ! empty( $config['money'] );
	}

	/**
	 * Top up a money-denominated balance from a MAJOR-unit amount.
	 *
	 * @since 1.5.0
	 *
	 * @param string           $slug     Plugin slug.
	 * @param int              $user_id  WordPress user ID.
	 * @param float|int|string $amount   Amount in major units (e.g. 147.35).
	 * @param string           $currency Optional ISO 4217 code; falls back to the consumer's money.currency.
	 * @param string           $note     Description.
	 * @return int|false Inserted row ID or false.
	 */
	public static function topup_money( string $slug, int $user_id, $amount, string $currency = '', string $note = '' ): int|false {
		return self::topup( $slug, $user_id, Money::to_minor( $amount, self::resolve_money_currency( $slug, $currency ) ), $note );
	}

	/**
	 * Reserve (hold) a money-denominated amount using a MAJOR-unit amount.
	 *
	 * @since 1.5.0
	 *
	 * @param string           $slug     Plugin slug.
	 * @param int              $user_id  WordPress user ID.
	 * @param float|int|string $amount   Amount in major units.
	 * @param int              $item_id  Associated item ID.
	 * @param string           $currency Optional ISO 4217 code.
	 * @param string           $note     Description.
	 * @return int|false Inserted row ID or false.
	 */
	public static function hold_money( string $slug, int $user_id, $amount, int $item_id, string $currency = '', string $note = '' ): int|false {
		return self::hold( $slug, $user_id, Money::to_minor( $amount, self::resolve_money_currency( $slug, $currency ) ), $item_id, $note );
	}

	/**
	 * Deduct from a money-denominated balance using a MAJOR-unit amount.
	 *
	 * @since 1.5.0
	 *
	 * @param string           $slug     Plugin slug.
	 * @param int              $user_id  WordPress user ID.
	 * @param float|int|string $amount   Amount in major units.
	 * @param int              $item_id  Associated item ID.
	 * @param string           $currency Optional ISO 4217 code.
	 * @param string           $note     Description.
	 * @return bool
	 */
	public static function deduct_money( string $slug, int $user_id, $amount, int $item_id, string $currency = '', string $note = '' ): bool {
		return self::deduct( $slug, $user_id, Money::to_minor( $amount, self::resolve_money_currency( $slug, $currency ) ), $item_id, $note );
	}

	/**
	 * Refund to a money-denominated balance using a MAJOR-unit amount.
	 *
	 * @since 1.5.0
	 *
	 * @param string           $slug     Plugin slug.
	 * @param int              $user_id  WordPress user ID.
	 * @param float|int|string $amount   Amount in major units.
	 * @param int              $item_id  Associated item ID.
	 * @param string           $currency Optional ISO 4217 code.
	 * @param string           $note     Description.
	 * @return int|false Inserted row ID or false.
	 */
	public static function refund_money( string $slug, int $user_id, $amount, int $item_id, string $currency = '', string $note = '' ): int|false {
		return self::refund( $slug, $user_id, Money::to_minor( $amount, self::resolve_money_currency( $slug, $currency ) ), $item_id, $note );
	}

	/**
	 * Adjust a money-denominated balance by a signed MAJOR-unit delta.
	 *
	 * @since 1.5.0
	 *
	 * @param string           $slug     Plugin slug.
	 * @param int              $user_id  WordPress user ID.
	 * @param float|int|string $amount   Signed amount in major units.
	 * @param string           $currency Optional ISO 4217 code.
	 * @param string           $note     Description.
	 * @return int|false Inserted row ID or false.
	 */
	public static function adjust_money( string $slug, int $user_id, $amount, string $currency = '', string $note = '' ): int|false {
		$currency_code = self::resolve_money_currency( $slug, $currency );
		$sign          = ( (float) $amount < 0 ) ? -1 : 1;
		$minor         = $sign * Money::to_minor( abs( (float) $amount ), $currency_code );
		return self::adjust( $slug, $user_id, $minor, $note );
	}

	/**
	 * Read a money-denominated balance as a MAJOR-unit amount for display.
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug     Plugin slug.
	 * @param int    $user_id  WordPress user ID.
	 * @param string $currency Optional ISO 4217 code.
	 * @return float Balance in major units.
	 */
	public static function balance_money( string $slug, int $user_id, string $currency = '' ): float {
		return Money::to_major( self::get_balance( $slug, $user_id ), self::resolve_money_currency( $slug, $currency ) );
	}

	/**
	 * Resolve the currency for a money-denominated operation.
	 *
	 * Explicit argument wins; otherwise the consumer's registered
	 * `money.currency` (a code or a callable); otherwise USD.
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $currency Explicit override (may be empty).
	 * @return string Upper-case ISO 4217 code.
	 */
	public static function resolve_money_currency( string $slug, string $currency = '' ): string {
		if ( '' !== $currency ) {
			return strtoupper( $currency );
		}

		$config   = Registry::instance()->get( $slug );
		$money    = is_array( $config['money'] ?? null ) ? $config['money'] : array();
		$resolved = $money['currency'] ?? '';

		if ( is_callable( $resolved ) ) {
			$resolved = call_user_func( $resolved );
		}

		$resolved = strtoupper( trim( (string) $resolved ) );

		return '' !== $resolved ? $resolved : 'USD';
	}

	/**
	 * Get the DB table prefix for a plugin slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Plugin slug.
	 * @return string Table prefix.
	 */
	private static function get_prefix( string $slug ): string {
		$config = Registry::instance()->get( $slug );
		return $config['prefix'] ?? $slug;
	}

	/**
	 * Invalidate per-request balance cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @return void
	 */
	private static function invalidate_cache( string $slug, int $user_id ): void {
		unset( self::$balance_cache[ $slug ][ $user_id ] );
	}

	/**
	 * Public cache invalidation for consumers that write ledger rows
	 * directly via {@see Ledger::insert()} rather than the Credits API
	 * (e.g. bridges with their own charge/refund semantics). Without this
	 * a consumer-side write leaves get_balance() stale for the rest of
	 * the request.
	 *
	 * @since 1.6.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @return void
	 */
	public static function forget_balance( string $slug, int $user_id ): void {
		self::invalidate_cache( $slug, $user_id );
	}

	/**
	 * Fire low balance action if balance is below threshold.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @return void
	 */
	private static function maybe_fire_low_balance( string $slug, int $user_id ): void {
		$config    = Registry::instance()->get( $slug );
		$threshold = (int) ( $config['settings']['low_threshold'] ?? 5 );
		$balance   = self::get_balance( $slug, $user_id );

		if ( $balance <= $threshold ) {
			/**
			 * Fires when a user's credit balance falls below the configured threshold.
			 *
			 * @since 1.0.0
			 *
			 * @param string $slug    Plugin slug.
			 * @param int    $user_id WordPress user ID.
			 * @param int    $balance Current balance.
			 */
			do_action( 'wbcom_credits_low', $slug, $user_id, $balance );
		}
	}
}
