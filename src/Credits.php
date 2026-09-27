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
		// Under the user's lock the balance is read live (and locking) every
		// time: a spend decision must never come from the request cache.
		$prefix = self::get_prefix( $slug );
		$locked = Ledger::in_user_lock( $prefix, $user_id );

		if ( ! $locked && isset( self::$balance_cache[ $slug ][ $user_id ] ) ) {
			return self::$balance_cache[ $slug ][ $user_id ];
		}

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

		if ( ! $locked ) {
			self::$balance_cache[ $slug ][ $user_id ] = $balance;
		}

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
	 * How many ledger rows a user has, to page get_ledger().
	 *
	 * @since 1.7.2
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @return int
	 */
	public static function count_ledger( string $slug, int $user_id ): int {
		return Ledger::count_for_user( self::get_prefix( $slug ), $user_id );
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

	/**
	 * Whether members may BUY credits right now.
	 *
	 * The one answer every purchase path asks: the gateway checkout route, and
	 * the WooCommerce / MemberPress / PMPro adapters, which stop mapped
	 * products from being bought. Separate from is_enabled() because balances
	 * stay readable, and payments already made are still credited, while
	 * selling is off.
	 *
	 * @since 1.7.2
	 *
	 * @param string $slug Plugin slug.
	 * @return bool
	 */
	public static function checkout_enabled( string $slug ): bool {
		/**
		 * Filter whether members may start a credit purchase.
		 *
		 * Consumers hook this to their own switch (Listora Pro's Monetization
		 * toggle). Only STARTING a purchase is gated: completing or claiming a
		 * payment already made, and refunds, always run.
		 *
		 * @since 1.7.2
		 *
		 * @param bool   $enabled Default: Credits::is_enabled( $slug ).
		 * @param string $slug    Consumer slug.
		 */
		return (bool) apply_filters( 'wbcom_credits_checkout_enabled', self::is_enabled( $slug ), $slug );
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
	 * @param string      $note       Human-readable note.
	 * @param string|null $expires_at UTC 'Y-m-d H:i:s' when these credits lapse, null for never (since 1.9.0).
	 * @param string      $reason     What happened (since 1.9.0): 'topup' (default) or 'purchase'.
	 * @param string      $reference  Order / session the credits came from (since 1.9.0).
	 * @param int         $item_id    Item the credits relate to, e.g. a refunded ad (since 1.9.1).
	 * @return int|false Inserted row ID or false.
	 */
	public static function topup( string $slug, int $user_id, int $amount, string $note = '', ?string $expires_at = null, string $reason = 'topup', string $reference = '', int $item_id = 0 ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$result = Ledger::insert( self::get_prefix( $slug ), $user_id, 'topup', abs( $amount ), $item_id, $note, $expires_at, $reason, $reference );

		if ( $result ) {
			/**
			 * Fires after credits are topped up, once the write has committed.
			 *
			 * @since 1.0.0
			 * @since 1.9.2 Fires after the SDK transaction commits; 5th arg $ledger_id.
			 *
			 * @param string $slug    Plugin slug.
			 * @param int    $user_id WordPress user ID.
			 * @param int    $amount    Credits added.
			 * @param string $note      Description.
			 * @param int    $ledger_id The ledger row written (since 1.9.2).
			 */
			self::emit( 'wbcom_credits_topped_up', $slug, $user_id, $amount, $note, (int) $result );
			self::maybe_fire_low_balance( $slug, $user_id );
		}

		return $result;
	}

	/**
	 * Place a hold (reserve credits) on an item, without checking the balance.
	 *
	 * Prefer {@see try_hold()}, which checks the balance under the user's
	 * lock. Use this only where an overdraft is intended, or inside
	 * {@see with_user_lock()} after your own check.
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
		$result = Ledger::insert( self::get_prefix( $slug ), $user_id, 'hold', -abs( $amount ), $item_id, $note, null, 'hold' );

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
			self::emit( 'wbcom_credits_held', $slug, $user_id, $amount, $item_id );

			// Check low balance threshold.
			self::maybe_fire_low_balance( $slug, $user_id );
		}

		return $result;
	}

	/**
	 * Convert the open hold on an item into a permanent deduction.
	 *
	 * Returns false when the item has no open hold (before 1.9.0 it
	 * "succeeded" and charged nothing). Prefer {@see settle_hold()} with the
	 * id hold() returned.
	 *
	 * @since 1.0.0
	 * @since 1.9.0 Settles an open hold only.
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
			self::emit( 'wbcom_credits_deducted', $slug, $user_id, $amount, $item_id );
			self::maybe_fire_low_balance( $slug, $user_id );
		}

		return $result;
	}

	/**
	 * Refund held credits: release the item's open hold, or, with none open,
	 * give the amount back as a refund.
	 *
	 * @since 1.0.0
	 * @since 1.9.0 Releases the open hold (its own amount) when there is one.
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

		$note   = $note ?: 'Credits refunded';
		$prefix = self::get_prefix( $slug );

		// Releasing the item's open hold is what this call has always been
		// for; with none open it gives the amount back as a plain refund.
		$open   = $item_id > 0 ? Ledger::open_holds( $prefix, $user_id, $item_id ) : array();
		$result = false;
		if ( $open ) {
			$release = Ledger::release( $prefix, $user_id, (int) $open[0]->id, $note );
			if ( false !== $release ) {
				$result = $release['id'];
				$amount = $release['amount'];
			}
		}
		if ( false === $result ) {
			$result = Ledger::insert( $prefix, $user_id, 'refund', abs( $amount ), $item_id, $note, null, 'refund' );
		}

		if ( $result ) {
			$context = array(
				'item_id'   => $item_id,
				'ledger_id' => (int) $result,
				'note'      => $note,
				// The event's reason stays 'hold_refund' for every call, as
				// listeners expect; the ledger row's reason says which it was.
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
			self::emit( 'wbcom_credits_refunded', $slug, $user_id, abs( $amount ), $context );
			self::maybe_fire_low_balance( $slug, $user_id );
		}

		return $result;
	}

	/**
	 * Cancel the open holds on an item (physical delete).
	 *
	 * Settled and released holds are never touched (before 1.9.0 they were
	 * deleted too, silently reversing the charge). Prefer
	 * {@see cancel_hold_by_id()}.
	 *
	 * @since 1.0.0
	 * @since 1.9.0 Open holds only.
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
	 * @param string $note      Admin note.
	 * @param string $reason    What happened (since 1.9.0): 'admin_adjust' (default) or 'gateway_refund'.
	 * @param string $reference Order / session it relates to (since 1.9.0).
	 * @return int|false Inserted row ID or false.
	 */
	public static function adjust( string $slug, int $user_id, int $amount, string $note = '', string $reason = 'admin_adjust', string $reference = '' ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$entry_type = $amount >= 0 ? 'topup' : 'deduction';
		$note       = $note ?: 'Admin adjustment';

		$result = Ledger::insert( self::get_prefix( $slug ), $user_id, $entry_type, $amount, 0, $note, null, $reason, $reference );

		if ( $result ) {
			/**
			 * Fires after an administrator adjusts a balance, in either direction.
			 *
			 * @since 1.9.0
			 *
			 * @param string $slug    Plugin slug.
			 * @param int    $user_id WordPress user ID.
			 * @param int    $amount  Signed amount (positive added, negative removed).
			 * @param string $note    Admin note.
			 */
			self::emit( 'wbcom_credits_adjusted', $slug, $user_id, $amount, $note );
			self::maybe_fire_low_balance( $slug, $user_id );
		}

		return $result;
	}

	/**
	 * Give credits back for an item, without it looking like a purchase.
	 *
	 * For a refund or a compensating credit a consumer books itself: it
	 * writes a `topup` row (reason `refund` by default) linked to the item,
	 * fires `wbcom_credits_credited`, and does NOT fire
	 * `wbcom_credits_topped_up`, which consumers map to "funds added" emails
	 * and purchase revenue. Consumers wrote Ledger::insert() directly to get
	 * this (1.9.2).
	 *
	 * @since 1.9.2
	 *
	 * @param string $slug      Plugin slug.
	 * @param int    $user_id   WordPress user ID.
	 * @param int    $amount    Ledger units to add (positive).
	 * @param int    $item_id   Item the credit belongs to (0 if none).
	 * @param string $note      Description.
	 * @param string $reason    What happened: 'refund' (default) or another reason code.
	 * @param string $reference Order / event it relates to.
	 * @return int|false Inserted row ID or false.
	 */
	public static function credit( string $slug, int $user_id, int $amount, int $item_id = 0, string $note = '', string $reason = 'refund', string $reference = '' ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$result = Ledger::insert( self::get_prefix( $slug ), $user_id, 'topup', abs( $amount ), $item_id, $note ?: 'Credits returned', null, $reason, $reference );

		if ( $result ) {
			/**
			 * Fires after credits are given back for an item (not a purchase).
			 *
			 * @since 1.9.2
			 *
			 * @param string $slug      Plugin slug.
			 * @param int    $user_id   WordPress user ID.
			 * @param int    $amount    Ledger units added.
			 * @param int    $item_id   Item ID.
			 * @param int    $ledger_id Ledger row written.
			 * @param string $reason    Reason code.
			 */
			self::emit( 'wbcom_credits_credited', $slug, $user_id, abs( $amount ), $item_id, (int) $result, sanitize_key( $reason ) );
			self::maybe_fire_low_balance( $slug, $user_id );
		}

		return $result;
	}

	/**
	 * Run $fn while holding this user's credit lock for the slug.
	 *
	 * Reading the balance and writing a hold are two statements, so two
	 * requests spending at once could both see enough credit and both write
	 * a hold. Everything that checks a balance before spending does it in
	 * here: the lock is a MySQL named lock, so it serialises spends across
	 * PHP workers, and the balance cache is dropped on entry so the check
	 * reads the ledger as it is now.
	 *
	 * @since 1.9.0
	 *
	 * @param string   $slug    Plugin slug.
	 * @param int      $user_id WordPress user ID.
	 * @param callable $fn      Work to do under the lock.
	 * @return mixed What $fn returned, or false when the lock could not be taken
	 *               (10 seconds by default, filter `wbcom_credits_lock_timeout`).
	 *               Re-entrant: nested calls for the same user run straight away.
	 */
	public static function with_user_lock( string $slug, int $user_id, callable $fn ): mixed {
		return Ledger::with_user_lock(
			self::get_prefix( $slug ),
			$user_id,
			static function () use ( $slug, $user_id, $fn ) {
				self::invalidate_cache( $slug, $user_id );
				return $fn();
			}
		);
	}

	/**
	 * Credit a purchase exactly once: claim the event and top up in one
	 * transaction.
	 *
	 * The adapters used to claim first and top up afterwards, as two
	 * writes: a fatal error between them kept the claim and lost the
	 * credit, and every retry was then a duplicate. Now either both land
	 * or neither does.
	 *
	 * @since 1.9.0
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $source   Claim namespace, e.g. 'adapter:woocommerce'.
	 * @param string $event_id Stable id of the payment, e.g. 'woo:order:123'. Also stored as the row's reference.
	 * @param int    $user_id  WordPress user ID.
	 * @param int    $amount   Ledger units to credit (positive).
	 * @param string $note     Description.
	 * @return int|false|null Row id; null when the event was already credited; false when the write failed.
	 */
	public static function topup_once( string $slug, string $source, string $event_id, int $user_id, int $amount, string $note = '' ): int|false|null {
		Ledger::begin();
		if ( ! Gateways\Processed_Events::claim( $slug, $source, $event_id ) ) {
			Ledger::commit();
			return null;
		}

		$id = self::topup( $slug, $user_id, $amount, $note, null, 'purchase', $event_id );
		if ( false === $id ) {
			Ledger::rollback();
			return false;
		}
		Ledger::commit();

		return $id;
	}

	/**
	 * Place a hold only if the balance covers it, atomically.
	 *
	 * The balance is read from the ledger (not the request cache) under the
	 * user's lock, so two requests cannot both pass the check and overdraw.
	 *
	 * @since 1.9.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $amount  Amount to reserve, ledger units (positive).
	 * @param int    $item_id Associated item ID.
	 * @param string $note    Description.
	 * @return int|false The hold id, or false when the balance is short (or the lock timed out).
	 */
	public static function try_hold( string $slug, int $user_id, int $amount, int $item_id, string $note = '' ): int|false {
		$amount = abs( $amount );

		return self::with_user_lock(
			$slug,
			$user_id,
			static function () use ( $slug, $user_id, $amount, $item_id, $note ) {
				if ( self::get_balance( $slug, $user_id ) < $amount ) {
					return false;
				}
				return self::hold( $slug, $user_id, $amount, $item_id, $note );
			}
		);
	}

	/**
	 * Charge immediately if the balance covers it, atomically.
	 *
	 * For consumers that charge per event (an impression, a click, a
	 * renewal) with no approval step. Same lock and uncached check as
	 * {@see try_hold()}. Fires `wbcom_credits_deducted` with item_id.
	 *
	 * @since 1.9.0
	 *
	 * @param string $slug      Plugin slug.
	 * @param int    $user_id   WordPress user ID.
	 * @param int    $amount    Amount to charge, ledger units (positive).
	 * @param int    $item_id   Associated item ID (0 if none).
	 * @param string $note      Description.
	 * @param string $reference Optional reference.
	 * @param bool   $allow_overdraft Charge even when the balance is short.
	 * @return int|false The spend row id, or false when the balance is short (or the lock timed out).
	 */
	public static function spend( string $slug, int $user_id, int $amount, int $item_id = 0, string $note = '', string $reference = '', bool $allow_overdraft = false ): int|false {
		$amount = abs( $amount );
		$prefix = self::get_prefix( $slug );

		$result = self::with_user_lock(
			$slug,
			$user_id,
			static function () use ( $slug, $prefix, $user_id, $amount, $item_id, $note, $reference, $allow_overdraft ) {
				if ( ! $allow_overdraft && self::get_balance( $slug, $user_id ) < $amount ) {
					return false;
				}
				$id = Ledger::insert( $prefix, $user_id, 'deduction', -$amount, $item_id, $note ?: 'Credits spent', null, 'spend', $reference );
				self::invalidate_cache( $slug, $user_id );
				return $id;
			}
		);

		if ( $result ) {
			/** This action is documented in src/Credits.php (deduct). */
			self::emit( 'wbcom_credits_deducted', $slug, $user_id, $amount, $item_id );
			self::maybe_fire_low_balance( $slug, $user_id );
		}

		return $result;
	}

	/**
	 * Settle one hold by the id hold()/try_hold() returned.
	 *
	 * @since 1.9.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $hold_id Hold row id.
	 * @param int    $amount  Amount to spend, ledger units; 0 spends what was held. At most what was held (1.9.2).
	 * @param string $note    Description.
	 * @return int|false The spend row id, or false when the hold is not open or $amount is more than was held.
	 */
	public static function settle_hold( string $slug, int $user_id, int $hold_id, int $amount = 0, string $note = '' ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$prefix = self::get_prefix( $slug );
		$hold   = Ledger::find_open_hold( $prefix, $user_id, $hold_id );
		$result = null === $hold ? false : Ledger::settle( $prefix, $user_id, $hold_id, abs( $amount ), $note );

		if ( $result && null !== $hold ) {
			/** This action is documented in src/Credits.php (deduct). */
			self::emit( 'wbcom_credits_deducted', $slug, $user_id, $amount > 0 ? abs( $amount ) : abs( (int) $hold->amount ), (int) $hold->item_id );
		}

		return $result;
	}

	/**
	 * Release one hold back to the balance, by id.
	 *
	 * @since 1.9.0
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @param int    $hold_id Hold row id.
	 * @param string $note    Description.
	 * @return int|false The release row id, or false when the hold is not open.
	 */
	public static function release_hold( string $slug, int $user_id, int $hold_id, string $note = '' ): int|false {
		self::invalidate_cache( $slug, $user_id );

		$release = Ledger::release( self::get_prefix( $slug ), $user_id, $hold_id, $note );
		if ( false === $release ) {
			return false;
		}

		/** This action is documented in src/Credits.php (refund). */
		self::emit(
			'wbcom_credits_refunded',
			$slug,
			$user_id,
			$release['amount'],
			array(
				'item_id'   => $release['item_id'],
				'ledger_id' => $release['id'],
				'note'      => $note,
				'reason'    => 'hold_refund',
			)
		);

		return $release['id'];
	}

	/**
	 * Ledger rows for reports and admin screens.
	 *
	 * Filters: user_id, item_id, entry_type, reason (string or list),
	 * reference, hold_id, since / until (UTC 'Y-m-d H:i:s'; convert a
	 * site-time range with get_gmt_from_date() first). Paging: limit
	 * (max 500), offset, order. Consumers use this instead of querying
	 * the ledger table.
	 *
	 * @since 1.9.0
	 *
	 * @param string              $slug Plugin slug.
	 * @param array<string,mixed> $args Filters and paging.
	 * @return array<int, object> Rows: id, user_id, item_id, entry_type, amount, note, reason, reference, hold_id, created_at (UTC).
	 */
	public static function query_ledger( string $slug, array $args = array() ): array {
		return (array) Ledger::query( self::get_prefix( $slug ), $args, 'rows' );
	}

	/**
	 * How many ledger rows match query_ledger() filters.
	 *
	 * @since 1.9.0
	 *
	 * @param string              $slug Plugin slug.
	 * @param array<string,mixed> $args Filters.
	 * @return int
	 */
	public static function count_ledger_rows( string $slug, array $args = array() ): int {
		return (int) Ledger::query( self::get_prefix( $slug ), $args, 'count' );
	}

	/**
	 * Total amount (ledger units, signed) of rows matching query_ledger() filters.
	 *
	 * @since 1.9.0
	 *
	 * @param string              $slug Plugin slug.
	 * @param array<string,mixed> $args Filters.
	 * @return int
	 */
	public static function sum_ledger( string $slug, array $args = array() ): int {
		return (int) Ledger::query( self::get_prefix( $slug ), $args, 'sum' );
	}

	/**
	 * Totals and row counts grouped by one column, for list pages and admin
	 * screens (one query for many users: pass `user_ids`).
	 *
	 * @since 1.9.2
	 *
	 * @param string              $slug     Plugin slug.
	 * @param array<string,mixed> $args     query_ledger() filters, plus `user_ids` (list).
	 * @param string              $group_by 'reason' (default), 'user_id', 'entry_type' or 'item_id'.
	 * @return array<string, array{total: int, count: int}> Group value => signed total (ledger units) and row count.
	 */
	public static function sum_ledger_grouped( string $slug, array $args = array(), string $group_by = 'reason' ): array {
		$args['group_by'] = $group_by;
		return (array) Ledger::query( self::get_prefix( $slug ), $args, 'grouped' );
	}

	/**
	 * One ledger row by id, or null when it doesn't exist.
	 *
	 * @since 1.9.2
	 *
	 * @param string $slug Plugin slug.
	 * @param int    $id   Ledger row id.
	 * @return object|null Row: id, user_id, item_id, entry_type, amount, note, reason, reference, hold_id, expires_at, created_at (UTC).
	 */
	public static function get_ledger_row( string $slug, int $id ): ?object {
		return Ledger::get_row( self::get_prefix( $slug ), $id );
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
	 * What a member can buy through mapped store items (WooCommerce products,
	 * PMPro levels, MemberPress memberships), with where to buy each.
	 *
	 * purchase_paths() says whether a mapping exists; this lists them, so a
	 * consumer's credits screen can show "10 credits - Starter pack" with a
	 * link instead of a bare button. Only items of available adapters with a
	 * link are listed.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return array<int, array{adapter: string, item_id: string, credits: int, label: string, url: string}>
	 */
	public static function mapped_offers( string $slug ): array {
		$registry = new Adapters\AdapterRegistry( $slug, self::get_prefix( $slug ) );
		$mappings = get_option( $slug . '_credit_mappings', array() );
		$rows     = array();

		foreach ( ( is_array( $mappings ) ? $mappings : array() ) as $key => $mapping ) {
			if ( is_array( $mapping ) && isset( $mapping['adapter'], $mapping['item_id'] ) ) {
				$rows[] = array( (string) $mapping['adapter'], (string) $mapping['item_id'], (int) ( $mapping['credits'] ?? 0 ) );
			} elseif ( is_array( $mapping ) ) {
				foreach ( $mapping as $item_id => $credits ) {
					$rows[] = array( (string) $key, (string) $item_id, (int) $credits );
				}
			}
		}

		$offers = array();
		foreach ( $rows as list( $adapter_id, $item_id, $credits ) ) {
			$adapter = $registry->get( $adapter_id );
			if ( $credits <= 0 || ! $adapter || ! $adapter->is_available() ) {
				continue;
			}

			$label = get_the_title( (int) $item_id );
			$url   = '';
			switch ( $adapter_id ) {
				case 'woocommerce':
				case 'woo_subscriptions':
				case 'memberpress':
					$url = (string) get_permalink( (int) $item_id );
					break;
				case 'pmpro':
					$level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( (int) $item_id ) : null;
					$label = $level ? (string) $level->name : $label;
					$url   = function_exists( 'pmpro_url' ) ? (string) pmpro_url( 'checkout', '?pmpro_level=' . (int) $item_id ) : '';
					break;
			}

			/**
			 * Filter where a mapped item is bought (e.g. for a custom adapter).
			 *
			 * @since 1.9.0
			 *
			 * @param string $url        Purchase URL ('' hides the offer).
			 * @param string $adapter_id Adapter id.
			 * @param string $item_id    Mapped item id.
			 * @param string $slug       Plugin slug.
			 */
			$url = (string) apply_filters( 'wbcom_credits_offer_url', $url, $adapter_id, $item_id, $slug );
			if ( '' === $url ) {
				continue;
			}

			$offers[] = array(
				'adapter' => $adapter_id,
				'item_id' => $item_id,
				'credits' => $credits,
				'label'   => '' !== $label ? $label : $adapter->get_label(),
				'url'     => $url,
			);
		}

		return $offers;
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
		// A route that is switched off is not a way to buy (1.7.2). The paths
		// themselves stay listed: they tell an owner what is configured.
		return self::checkout_enabled( $slug ) && in_array( true, self::purchase_paths( $slug ), true );
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
	 * @param string|null      $expires_at UTC 'Y-m-d H:i:s' when these credits lapse (since 1.9.0).
	 * @param string           $reason     See topup() (since 1.9.0).
	 * @param string           $reference  See topup() (since 1.9.0).
	 * @return int|false Inserted row ID or false.
	 */
	public static function topup_money( string $slug, int $user_id, $amount, string $currency = '', string $note = '', ?string $expires_at = null, string $reason = 'topup', string $reference = '' ): int|false {
		return self::topup( $slug, $user_id, Money::to_minor( $amount, self::resolve_money_currency( $slug, $currency ) ), $note, $expires_at, $reason, $reference );
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
	 * @param string           $note      Description.
	 * @param string           $reason    See adjust() (since 1.9.0).
	 * @param string           $reference See adjust() (since 1.9.0).
	 * @return int|false Inserted row ID or false.
	 */
	public static function adjust_money( string $slug, int $user_id, $amount, string $currency = '', string $note = '', string $reason = 'admin_adjust', string $reference = '' ): int|false {
		$currency_code = self::resolve_money_currency( $slug, $currency );
		$sign          = ( (float) $amount < 0 ) ? -1 : 1;
		$minor         = $sign * Money::to_minor( abs( (float) $amount ), $currency_code );
		return self::adjust( $slug, $user_id, $minor, $note, $reason, $reference );
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
	 * Whether the loaded SDK has every method a consumer calls.
	 *
	 * Another plugin's older copy can win the load order, and then a newer
	 * method is missing. Pass the Credits methods you call; show your own
	 * admin notice and hide the credits UI when this is false. Guard the call
	 * itself too, since copies before 1.9.2 don't have it:
	 * `method_exists( Credits::class, 'sdk_ready' ) && Credits::sdk_ready( [...] )`.
	 *
	 * @since 1.9.2
	 * @param string[] $methods Credits method names.
	 * @return bool
	 */
	public static function sdk_ready( array $methods ): bool {
		foreach ( $methods as $method ) {
			if ( ! method_exists( self::class, (string) $method ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fire an SDK action once the current SDK transaction commits (now when
	 * none is open), and never for a write that rolled back.
	 *
	 * @param string $hook    Action name.
	 * @param mixed  ...$args Action arguments.
	 * @return void
	 */
	private static function emit( string $hook, ...$args ): void {
		Ledger::after_commit(
			static function () use ( $hook, $args ) {
				do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- documented at each call site.
			}
		);
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
	 * Public since 1.7.2 for consumers that serialise spends with their own
	 * lock: a balance read earlier in the request (a pre-check) is cached, so
	 * after taking the lock the next read must come from the ledger or two
	 * requests both pass the check (found on WB Listora, card 10336800031).
	 *
	 * @since 1.0.0
	 * @since 1.7.2 Public.
	 *
	 * @param string $slug    Plugin slug.
	 * @param int    $user_id WordPress user ID.
	 * @return void
	 */
	public static function invalidate_cache( string $slug, int $user_id ): void {
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
		$threshold = $config['settings']['low_threshold'] ?? 5;

		// The setting is what an owner types: credits, or for a money
		// consumer an amount of money (5 = 5.00, not 5 cents).
		$threshold = self::is_money( $slug )
			? Money::to_minor( $threshold, self::resolve_money_currency( $slug ) )
			: (int) $threshold;
		$balance   = self::get_balance( $slug, $user_id );
		$flag      = '_wbcom_credits_low_' . sanitize_key( $slug );

		// Once per crossing: the flag is set when the alert fires and cleared
		// when the balance climbs back above the threshold. Without it every
		// spend below the threshold sent another "running low" email.
		if ( $balance > $threshold ) {
			delete_user_meta( $user_id, $flag );
			return;
		}
		if ( get_user_meta( $user_id, $flag, true ) ) {
			return;
		}
		update_user_meta( $user_id, $flag, 1 );

		/**
		 * Fires when a user's credit balance falls to or below the configured
		 * threshold. Once per crossing since 1.9.0; it fires again only after
		 * the balance has gone back above the threshold.
		 *
		 * @since 1.0.0
		 *
		 * @param string $slug    Plugin slug.
		 * @param int    $user_id WordPress user ID.
		 * @param int    $balance Current balance.
		 */
		self::emit( 'wbcom_credits_low', $slug, $user_id, $balance );
	}
}
