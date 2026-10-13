<?php
/**
 * Consumer — auto-wires hold/deduct/refund hooks for a credit-consuming action.
 *
 * @package Wbcom\Credits
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Represents something a plugin "sells" that costs credits.
 *
 * Each consumer auto-wires three WordPress action hooks:
 * - hold_on:   reserves credits when item is submitted
 * - deduct_on: settles (permanently deducts) when item is approved
 * - refund_on: releases hold when item is rejected
 *
 * @since 1.0.0
 */
final class Consumer {

	/**
	 * Plugin slug this consumer belongs to.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Plugin DB prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Consumer configuration.
	 *
	 * @var array{id: string, label: string, cost: int|float|callable, hold_on: string, deduct_on: string, refund_on: string}
	 */
	private array $config;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $prefix Plugin DB prefix.
	 * @param array  $config Consumer configuration.
	 */
	public function __construct( string $slug, string $prefix, array $config ) {
		$this->slug   = $slug;
		$this->prefix = $prefix;
		$this->config = wp_parse_args(
			$config,
			array(
				'id'        => '',
				'label'     => '',
				'cost'      => 0,
				'hold_on'   => '',
				'deduct_on' => '',
				'refund_on' => '',
			)
		);
	}

	/**
	 * Register WordPress action hooks for the hold/deduct/refund lifecycle.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! empty( $this->config['hold_on'] ) ) {
			add_action( $this->config['hold_on'], array( $this, 'on_hold' ), 10, 1 );
		}

		if ( ! empty( $this->config['deduct_on'] ) ) {
			add_action( $this->config['deduct_on'], array( $this, 'on_deduct' ), 10, 1 );
		}

		if ( ! empty( $this->config['refund_on'] ) ) {
			add_action( $this->config['refund_on'], array( $this, 'on_refund' ), 10, 1 );
		}
	}

	/**
	 * Handle the hold event — reserve credits when item is submitted.
	 *
	 * Expects the action to pass the item (post) ID as first argument.
	 *
	 * @since 1.0.0
	 *
	 * @param int $item_id Post/item ID.
	 * @return void
	 */
	public function on_hold( int $item_id ): void {
		$this->reserve_item( $item_id );
	}

	/**
	 * Handle the deduct event — permanently deduct credits on approval.
	 *
	 * @since 1.0.0
	 *
	 * @param int $item_id Post/item ID.
	 * @return void
	 */
	public function on_deduct( int $item_id ): void {
		$this->settle_item( $item_id );
	}

	/**
	 * Handle the refund event — release held credits on rejection.
	 *
	 * @since 1.0.0
	 *
	 * @param int $item_id Post/item ID.
	 * @return void
	 */
	public function on_refund( int $item_id ): void {
		$this->release_item( $item_id );
	}

	/**
	 * Reserve the item's cost from its author, if they can afford it.
	 *
	 * The balance check and the hold run under the author's credit lock, so
	 * parallel submissions can't both spend the same credits.
	 *
	 * @since 1.9.0
	 *
	 * @since 1.10.1 `$payer`.
	 *
	 * @param int $item_id Post/item ID.
	 * @param int $payer   Account that pays; 0 for the item's author.
	 * @return bool True when the item is paid for (held now or before, settled, or free); false when the payer can't afford it.
	 */
	public function reserve_item( int $item_id, int $payer = 0 ): bool {
		return (bool) $this->with_item_lock( $item_id, fn (): bool => $this->reserve_item_unlocked( $item_id, $payer ) );
	}

	/**
	 * reserve_item() under the item lock.
	 *
	 * @param int $item_id Post/item ID.
	 * @param int $payer   Account that pays; 0 for the default.
	 * @return bool
	 */
	private function reserve_item_unlocked( int $item_id, int $payer = 0 ): bool {
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$user_id = $payer > 0 ? $payer : (int) $post->post_author;

		return (bool) Credits::with_user_lock(
			$this->slug,
			$user_id,
			function () use ( $item_id, $user_id, $post ): bool {
				// Held or settled already: a second hold event (a resubmission,
				// a retried request) must not reserve again.
				if ( in_array( $this->record( $item_id )['state'], array( 'held', 'settled' ), true ) ) {
					return true;
				}

				// A free item records a zero hold, so moving it to a paid tier
				// later reprices from 0 and charges the full difference.
				$cost = max( 0, $this->resolve_cost( $item_id ) );
				if ( 0 === $this->units( $cost ) ) {
					$this->store( $item_id, 'held', 0, $user_id, $post );
					return true;
				}

				if ( $this->units( $this->current_balance( $user_id ) ) < $this->units( $cost ) ) {
					return false;
				}

				if ( ! $this->reserve( $user_id, $cost, $item_id, $this->config['label'] . ' — credits held' ) ) {
					return false;
				}
				$this->store( $item_id, 'held', $cost, $user_id, $post );
				return true;
			}
		);
	}

	/**
	 * Settle the item's open hold, for the amount held.
	 *
	 * @since 1.9.0
	 *
	 * @param int $item_id Post/item ID.
	 * @return bool True when the item is settled (now or before); false when there was no open hold to settle.
	 */
	public function settle_item( int $item_id ): bool {
		return (bool) $this->with_item_lock( $item_id, fn (): bool => $this->settle_item_unlocked( $item_id ) );
	}

	/**
	 * settle_item() under the item lock.
	 *
	 * @param int $item_id Post/item ID.
	 * @return bool
	 */
	private function settle_item_unlocked( int $item_id ): bool {
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$record  = $this->record( $item_id );
		$user_id = $this->payer( $record, $post );

		// Recorded items settle only an open hold, for the amount held. A
		// republish of a settled item (renewal, reactivation) or an item
		// whose hold was never placed charges nothing more.
		if ( '' !== $record['state'] ) {
			if ( 'held' === $record['state'] ) {
				$this->settle_holds( $user_id, $item_id, $this->config['label'] . ' — credits deducted' );
				$this->store( $item_id, 'settled', $record['cost'], $user_id, $post );
				return true;
			}
			return 'settled' === $record['state'];
		}

		// Items held before per-item records existed (pre-1.7.2): settle
		// whatever is still held on the item. Before 1.9.0 this wrote a
		// release and a deduction even with nothing held, charging nothing.
		return $this->settle_holds( $user_id, $item_id, $this->config['label'] . ' — credits deducted' ) > 0 || $this->resolve_cost( $item_id ) <= 0;
	}

	/**
	 * Release the item's open hold back to the account that paid it.
	 *
	 * @since 1.9.0
	 *
	 * @param int $item_id Post/item ID.
	 * @return bool True when a hold was released now.
	 */
	public function release_item( int $item_id ): bool {
		return (bool) $this->with_item_lock( $item_id, fn (): bool => $this->release_item_unlocked( $item_id ) );
	}

	/**
	 * release_item() under the item lock.
	 *
	 * @param int $item_id Post/item ID.
	 * @return bool
	 */
	private function release_item_unlocked( int $item_id ): bool {
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$record  = $this->record( $item_id );
		$user_id = $this->payer( $record, $post );

		// Recorded items release only an open hold. Deactivating or trashing
		// an item whose credits were already settled used to refund them,
		// so a member could take a paid listing down, get paid back and put
		// it up again for free.
		if ( '' !== $record['state'] ) {
			if ( 'held' === $record['state'] ) {
				$released = $this->release_holds( $user_id, $item_id, $this->config['label'] . ' — credits refunded' );
				$this->store( $item_id, 'released', $record['cost'], $user_id, $post );
				return $released > 0;
			}
			return false;
		}

		return $this->release_holds( $user_id, $item_id, $this->config['label'] . ' — credits refunded' ) > 0;
	}

	/**
	 * Bring a held or settled item in line with its current cost.
	 *
	 * For an item whose price changed after it was paid for (moved to a
	 * dearer or cheaper tier): the difference is held or settled on top, or
	 * handed back. Items with nothing charged are left alone; their next
	 * reserve prices them fresh.
	 *
	 * When `$payer` is another account than the one that paid, the item
	 * changes hands: the new payer is charged the full current cost and the
	 * previous payer gets back what they paid. One account pays for an
	 * item, so a later refund has one place to go.
	 *
	 * @since 1.9.0
	 * @since 1.10.1 `$payer`.
	 *
	 * @param int $item_id Post/item ID.
	 * @param int $payer   Account making the change; 0 for whoever paid.
	 * @return bool False only when the payer can't afford a price rise.
	 */
	public function reprice_item( int $item_id, int $payer = 0 ): bool {
		return (bool) $this->with_item_lock( $item_id, fn (): bool => $this->reprice_item_unlocked( $item_id, $payer ) );
	}

	/**
	 * reprice_item() under the item lock.
	 *
	 * @param int $item_id Post/item ID.
	 * @param int $payer   Account that pays; 0 for the default.
	 * @return bool
	 */
	private function reprice_item_unlocked( int $item_id, int $payer = 0 ): bool {
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$paid_by = $this->payer( $this->record( $item_id ), $post );
		$user_id = $payer > 0 ? $payer : $paid_by;
		if ( $user_id !== $paid_by ) {
			return $this->change_payer( $item_id, $paid_by, $user_id );
		}

		return (bool) Credits::with_user_lock(
			$this->slug,
			$user_id,
			function () use ( $item_id, $user_id, $post ): bool {
				$record = $this->record( $item_id );
				if ( ! in_array( $record['state'], array( 'held', 'settled' ), true ) ) {
					return true;
				}
				if ( $this->payer( $record, $post ) !== $user_id ) {
					return false; // Unreachable under the item lock; kept as a guard.
				}

				$cost  = max( 0, $this->resolve_cost( $item_id ) );
				$delta = $cost - $record['cost'];
				// Compared in ledger units: money costs carry decimals, and
				// 2.5 - 2.4 is not exactly 0.1 in floating point.
				$delta_units = $this->units( $cost ) - $this->units( $record['cost'] );
				if ( 0 === $delta_units ) {
					return true;
				}

				$label = $this->config['label'];
				if ( $delta_units > 0 ) {
					if ( $this->units( $this->current_balance( $user_id ) ) < $delta_units ) {
						return false;
					}
					if ( ! $this->reserve( $user_id, $delta, $item_id, $label . ' — price change held' ) ) {
						return false;
					}
					if ( 'settled' === $record['state'] ) {
						$this->settle_holds( $user_id, $item_id, $label . ' — price change deducted' );
					}
				} elseif ( 'held' === $record['state'] ) {
					// A hold can't be partly released: release it and hold
					// the new, lower price (always affordable, under the lock).
					$this->release_holds( $user_id, $item_id, $label . ' — price change released' );
					if ( $this->units( $cost ) > 0 && ! $this->reserve( $user_id, $cost, $item_id, $label . ' — credits held' ) ) {
						return false;
					}
				} else {
					$this->give_back( $user_id, -$delta, $item_id, $label . ' — price change refunded' );
				}

				$this->store( $item_id, $record['state'], $cost, $user_id, $post );
				return true;
			}
		);
	}

	/**
	 * Hand a held or settled item to another payer at its current cost.
	 *
	 * Both ledgers are locked, lower user ID first so two hand-overs the
	 * other way round can't deadlock. The new payer is charged before the
	 * old one is paid back, so a short balance changes nothing.
	 *
	 * @since 1.10.1
	 *
	 * @param int $item_id Post/item ID.
	 * @param int $from    Account that paid.
	 * @param int $to      Account taking it over.
	 * @return bool False when `$to` can't afford it.
	 */
	private function change_payer( int $item_id, int $from, int $to ): bool {
		$work = function () use ( $item_id, $from, $to ): bool {
			$record = $this->record( $item_id );
			if ( ! in_array( $record['state'], array( 'held', 'settled' ), true ) ) {
				return true;
			}
			if ( $this->payer( $record, get_post( $item_id ) ) !== $from ) {
				return false; // Unreachable under the item lock; kept as a guard.
			}

			$label = $this->config['label'];
			$cost  = max( 0, $this->resolve_cost( $item_id ) );
			if ( $this->units( $cost ) > 0 ) {
				if ( $this->units( $this->current_balance( $to ) ) < $this->units( $cost ) ) {
					return false;
				}
				if ( ! $this->reserve( $to, $cost, $item_id, $label . ' — credits held' ) ) {
					return false;
				}
				if ( 'settled' === $record['state'] ) {
					$this->settle_holds( $to, $item_id, $label . ' — credits deducted' );
				}
			}

			if ( 'held' === $record['state'] ) {
				$this->release_holds( $from, $item_id, $label . ' — credits refunded' );
			} elseif ( $this->units( $record['cost'] ) > 0 ) {
				$this->give_back( $from, $record['cost'], $item_id, $label . ' — credits refunded' );
			}

			$this->store( $item_id, $record['state'], $cost, $to, get_post( $item_id ) );
			return true;
		};

		$first  = min( $from, $to );
		$second = max( $from, $to );
		return (bool) Credits::with_user_lock(
			$this->slug,
			$first,
			fn () => Credits::with_user_lock( $this->slug, $second, $work )
		);
	}

	/**
	 * Run a callback holding this item's lock, so two accounts acting on one
	 * item (co-owners) are served one at a time. Take it before any user
	 * lock: every item operation locks the item first, then the ledger(s).
	 * Re-entrant.
	 *
	 * @since 1.10.1
	 *
	 * @param int      $item_id Post/item ID.
	 * @param callable $fn      Work; its return value is passed back.
	 * @return mixed The callback's result, or false when the lock timed out.
	 */
	public function with_item_lock( int $item_id, callable $fn ): mixed {
		return Ledger::with_lock( 'consumer|' . $this->slug . '|' . $this->config['id'] . '|' . $item_id, $fn );
	}

	/**
	 * The account an item's charges belong to: the recorded payer, else the
	 * author (items recorded before 1.10.1, and every item a consumer never
	 * passed a payer for).
	 *
	 * @since 1.10.1
	 *
	 * @param array{state: string, cost: int|float, payer?: int} $record Item record.
	 * @param \WP_Post|null                                     $post   Item.
	 * @return int
	 */
	private function payer( array $record, $post ): int {
		$payer = (int) ( $record['payer'] ?? 0 );
		return $payer > 0 ? $payer : ( $post instanceof \WP_Post ? (int) $post->post_author : 0 );
	}

	/**
	 * This consumer's record for an item: state and the amount it holds.
	 *
	 * States: '' (nothing recorded - items from before 1.7.2 behave as
	 * they always did), 'held', 'settled', 'released'.
	 *
	 * @since 1.9.0 Public.
	 *
	 * @param int $item_id Post/item ID.
	 * @since 1.10.1 `payer`: the account charged, present only when it is not the author.
	 * @return array{state: string, cost: int|float, payer?: int} Cost in resolve_cost() units: credits, or money (may have decimals) on a money consumer.
	 */
	public function record( int $item_id ): array {
		$raw    = get_post_meta( $item_id, $this->meta_key(), true );
		$record = array(
			'state' => is_array( $raw ) ? (string) ( $raw['state'] ?? '' ) : '',
			'cost'  => $this->normalise( is_array( $raw ) ? ( $raw['cost'] ?? 0 ) : 0 ),
		);
		if ( is_array( $raw ) && (int) ( $raw['payer'] ?? 0 ) > 0 ) {
			$record['payer'] = (int) $raw['payer'];
		}
		return $record;
	}

	/**
	 * Record an item's state.
	 *
	 * Consumers upgrading items charged before records existed can seed one
	 * here (e.g. from their own ledger rows).
	 *
	 * @since 1.9.0 Public.
	 *
	 * @param int    $item_id Post/item ID.
	 * @param string $state   held | settled | released.
	 * @param int|float $cost Amount held, in resolve_cost() units (decimals allowed on a money consumer since 1.9.4).
	 * @return void
	 */
	public function set_state( int $item_id, string $state, int|float $cost ): void {
		$record = $this->record( $item_id ); // Keeps the payer (1.10.1).
		update_post_meta( $item_id, $this->meta_key(), array( 'state' => $state, 'cost' => $this->normalise( $cost ) ) + array_intersect_key( $record, array( 'payer' => 0 ) ) );
	}

	/**
	 * Record an item's state and who paid, the payer kept only when it is
	 * not the author, so items paid by their author look as they always did.
	 *
	 * @since 1.10.1
	 *
	 * @param int           $item_id Post/item ID.
	 * @param string        $state   held | settled | released.
	 * @param int|float     $cost    Amount held.
	 * @param int           $payer   Account charged.
	 * @param \WP_Post|null $post    Item.
	 * @return void
	 */
	private function store( int $item_id, string $state, int|float $cost, int $payer, $post ): void {
		$record = array( 'state' => $state, 'cost' => $this->normalise( $cost ) );
		if ( $post instanceof \WP_Post && $payer > 0 && $payer !== (int) $post->post_author ) {
			$record['payer'] = $payer;
		}
		update_post_meta( $item_id, $this->meta_key(), $record );
	}

	/**
	 * Post meta key holding this consumer's record, for consumers that look
	 * items up in SQL (e.g. to find items charged before records existed).
	 *
	 * @since 1.9.0 Public.
	 * @return string
	 */
	public function meta_key(): string {
		return '_wbcom_credits_' . sanitize_key( $this->slug ) . '_' . sanitize_key( (string) $this->config['id'] );
	}

	/**
	 * Read the user's balance in the same units resolve_cost() returns.
	 *
	 * When the slug is registered in money mode the SDK ledger stores integer
	 * MINOR units, so the raw Credits::get_balance()/hold()/deduct()/refund()
	 * methods would treat a MAJOR-unit cost (e.g. a 5-credit listing fee) as 5
	 * minor units — a ~100x under-charge on a hundredths-based currency. Dispatch
	 * to the *_money() variants (which convert major→minor) whenever the consumer
	 * runs in money mode, and to the raw methods otherwise.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return float Balance in major units when money mode, else raw credits.
	 */
	private function current_balance( int $user_id ): float {
		return Credits::is_money( $this->slug )
			? Credits::balance_money( $this->slug, $user_id )
			: (float) Credits::get_balance( $this->slug, $user_id );
	}

	/**
	 * Reserve credits for the cost, money-mode aware.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int|float $cost    Cost in the unit resolve_cost() returns.
	 * @param int       $item_id Item ID.
	 * @param string    $note    Ledger note.
	 * @return bool Whether the hold was written.
	 */
	private function reserve( int $user_id, int|float $cost, int $item_id, string $note ): bool {
		if ( Credits::is_money( $this->slug ) ) {
			return false !== Credits::hold_money( $this->slug, $user_id, (float) $cost, $item_id, '', $note );
		}
		return false !== Credits::hold( $this->slug, $user_id, $cost, $item_id, $note );
	}

	/**
	 * Settle every open hold on the item, each for the amount it holds.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $item_id Item ID.
	 * @param string $note    Ledger note.
	 * @return int How many holds were settled.
	 */
	private function settle_holds( int $user_id, int $item_id, string $note ): int {
		$settled = 0;
		foreach ( Ledger::open_holds( $this->prefix, $user_id, $item_id ) as $hold ) {
			if ( false !== Credits::settle_hold( $this->slug, $user_id, (int) $hold->id, 0, $note ) ) {
				++$settled;
			}
		}
		return $settled;
	}

	/**
	 * Release every open hold on the item back to the account that placed it.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $item_id Item ID.
	 * @param string $note    Ledger note.
	 * @return int How many holds were released.
	 */
	private function release_holds( int $user_id, int $item_id, string $note ): int {
		$released = 0;
		foreach ( Ledger::open_holds( $this->prefix, $user_id, $item_id ) as $hold ) {
			if ( false !== Credits::release_hold( $this->slug, $user_id, (int) $hold->id, $note ) ) {
				++$released;
			}
		}
		return $released;
	}

	/**
	 * Give part of a settled charge back, money-mode aware.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int|float $amount  Amount in the unit resolve_cost() returns.
	 * @param int       $item_id Item ID.
	 * @param string    $note    Ledger note.
	 * @return void
	 */
	private function give_back( int $user_id, int|float $amount, int $item_id, string $note ): void {
		if ( Credits::is_money( $this->slug ) ) {
			Credits::refund_money( $this->slug, $user_id, (float) $amount, $item_id, '', $note );
			return;
		}
		Credits::refund( $this->slug, $user_id, (int) $amount, $item_id, $note );
	}

	/**
	 * Resolve the cost — a number or a callable.
	 *
	 * Credits on a token consumer (whole numbers). On a money consumer, an
	 * amount of money that may have decimals: a 2.50 fee used to be cast to
	 * int and charged as 2.00 (1.9.4).
	 *
	 * @since 1.0.0
	 * @since 1.9.4 Decimals kept on a money consumer.
	 *
	 * @param int $item_id Item ID for dynamic cost lookups.
	 * @return int|float Cost.
	 */
	private function resolve_cost( int $item_id ): int|float {
		$cost = $this->config['cost'];

		return $this->normalise( is_callable( $cost ) ? call_user_func( $cost, $item_id ) : $cost );
	}

	/**
	 * A cost as this consumer counts it: a whole number of credits, or an
	 * amount of money rounded to the currency's decimals.
	 *
	 * @param mixed $cost Raw cost.
	 * @return int|float
	 */
	private function normalise( $cost ): int|float {
		if ( ! Credits::is_money( $this->slug ) ) {
			return (int) $cost;
		}
		$currency = Credits::resolve_money_currency( $this->slug );
		$amount   = Money::to_major( Money::to_minor( is_numeric( $cost ) ? $cost : 0, $currency ), $currency );

		// Whole amounts stay ints, as records before 1.9.4 held them.
		return floor( $amount ) === $amount ? (int) $amount : $amount;
	}

	/**
	 * A cost or balance in ledger units, for exact comparison.
	 *
	 * @param int|float $amount Amount in resolve_cost() units.
	 * @return int
	 */
	private function units( int|float $amount ): int {
		return Credits::is_money( $this->slug )
			? Money::to_minor( $amount, Credits::resolve_money_currency( $this->slug ) )
			: (int) $amount;
	}
}
