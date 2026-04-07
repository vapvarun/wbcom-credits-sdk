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
			/**
			 * Fires after credits are refunded.
			 *
			 * @since 1.0.0
			 *
			 * @param string $slug    Plugin slug.
			 * @param int    $user_id WordPress user ID.
			 * @param int    $item_id Item ID.
			 */
			do_action( 'wbcom_credits_refunded', $slug, $user_id, $item_id );
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
