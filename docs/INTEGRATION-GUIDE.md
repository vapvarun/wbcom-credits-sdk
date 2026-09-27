# Integrating the Credits SDK into a plugin

A step-by-step guide for a plugin that wants credits or a money balance: bundle the SDK, register, take money in, charge for things, give money back, and report on it. Written against **1.9.4**.

Read [CONSUMER-RULES.md](CONSUMER-RULES.md) alongside this. The rules say what every consumer must and must not do; this guide shows how. Payment gateways and the checkout screen have their own guides, linked where they come up.

---

## 1. Decide: tokens or money

| Mode | The balance is | Example |
|---|---|---|
| **Tokens** (default) | A count: "12 credits" | 1 credit = 1 job post |
| **Money** | An amount of money: "$12.50" | An advertiser's ad budget |

The ledger always stores integers. In money mode those integers are the currency's **minor units** (cents for USD, yen for JPY, fils for KWD). You never multiply by 100 yourself. Pick the mode once, in registration (step 3). Changing it later reinterprets every stored balance.

---

## 2. Bundle the SDK

1. Copy the SDK release into `libs/wbcom-credits-sdk/` in your plugin. Take `assets/`, `docs/`, `src/`, `templates/`, `wbcom-credits-sdk.php`, `composer.json`, `CHANGELOG.md`, `CONSUMERS.md`, `README.md` and `ROADMAP.md` from a tag (for example `v1.9.1`).
2. Write the tag's commit sha into `libs/wbcom-credits-sdk/.bundled-from`.
3. **Commit the bundle.** It is shipped code, not a dev dependency.
4. Include it while your main plugin file runs, not on a later hook:

```php
// my-plugin.php (top level, not inside a hook)
require_once __DIR__ . '/libs/wbcom-credits-sdk/wbcom-credits-sdk.php';
```

Several plugins can bundle the SDK on one site. Each copy announces itself, and every class loads from the newest version announced. That is why each copy must be included early, and why you guard your calls (step 4).

5. Add your plugin to [CONSUMERS.md](../CONSUMERS.md) in the same change.

Never edit files under `libs/wbcom-credits-sdk/`. A bug there is fixed in the SDK repo and re-bundled (CONSUMER-RULES, rule 1).

---

## 3. Register

Register on `wbcom_credits_sdk_registry`. The SDK fires it during `after_setup_theme`, then creates or upgrades your tables and wires your hooks.

```php
add_action( 'wbcom_credits_sdk_registry', function ( $registry ) {
	$registry->register(
		array(
			'slug'      => 'my-plugin',            // Required. Every Credits:: call passes this.
			'prefix'    => 'mp',                   // Required. Tables: {wp_prefix}mp_credit_ledger, ...
			'version'   => MY_PLUGIN_VERSION,
			'file'      => MY_PLUGIN_FILE,
			'user_type' => 'member',               // Wording only.

			// Money mode: leave this out for tokens.
			'money'     => array( 'currency' => 'USD' ), // Or a callable returning the code.

			'settings'  => array(
				'low_threshold' => 5,              // Credits, or an amount of money (5 = 5.00).
				'purchase_url'  => '',             // Optional external "buy" page.
			),

			// Optional: where a buyer lands after paying (string or callable).
			'return_url' => static fn () => home_url( '/my-account/balance/' ),

			// Optional: things that cost credits, charged automatically (step 6a).
			'consumers' => array(),
		)
	);
} );
```

What you get:
- the `{prefix}_credit_ledger` table (plus the gateway log and processed-events tables), upgraded on every SDK update;
- the REST routes under `/wp-json/wbcom-credits/v1/my-plugin/`;
- the WooCommerce, PMPro, MemberPress, WooCommerce Subscriptions and Memberships adapters, wherever those plugins are active.

---

## 4. Guard every call

An older copy of the SDK bundled by another plugin can win on a site that runs both. Check for the methods you actually call, not for the class:

```php
function my_plugin_credits_ready(): bool {
	static $ready = null;
	if ( null === $ready ) {
		$ready = class_exists( '\Wbcom\Credits\Credits' )
			&& method_exists( '\Wbcom\Credits\Credits', 'sdk_ready' ) // 1.9.2+
			&& \Wbcom\Credits\Credits::sdk_ready( array( 'try_hold', 'settle_hold', 'release_hold', 'spend', 'credit', 'query_ledger' ) );
	}
	return $ready;
}
```

List every method you call.

When it returns false, hide the credits UI and show the site owner an admin notice ("another plugin ships an older Credits SDK; update it"). Never fatal.

---

## 5. Take money in

A balance goes up in one of four ways. Your code writes only the last.

| Route | Who writes the ledger | You do |
|---|---|---|
| **Stripe / PayPal checkout** | The SDK gateways | Configure packs and keys: [CONSUMER_GATEWAY_INTEGRATION.md](CONSUMER_GATEWAY_INTEGRATION.md), [CONSUMER_FRONTEND_CHECKOUT.md](CONSUMER_FRONTEND_CHECKOUT.md), [SETUP-STRIPE.md](SETUP-STRIPE.md), [SETUP-PAYPAL.md](SETUP-PAYPAL.md) |
| **A store product** (WooCommerce, PMPro, MemberPress, ...) | The SDK adapters | Save mappings in the `{slug}_credit_mappings` option (below) |
| **Admin adjustment** | `Credits::adjust()` / REST `POST /topup` | Give admins a screen, or use the REST route |
| **Your own payment flow** | `Credits::topup_once()` | Call it once per payment (below) |

Mappings: a product's credits are ledger units, so in money mode that means minor units.

```php
update_option( 'my-plugin_credit_mappings', array(
	array( 'adapter' => 'woocommerce', 'item_id' => 123, 'credits' => 10 ),
) );
```

Your own payment flow: pass a stable id for the payment. The claim and the credit are written in one transaction, so a retry or a duplicate webhook never credits twice and a crash never loses the credit.

```php
$row = \Wbcom\Credits\Credits::topup_once(
	'my-plugin',
	'my-plugin:orders',          // Claim namespace.
	'order:' . $order_id,        // Stable id; also stored as the row's reference.
	$user_id,
	$ledger_units,               // Money mode: Money::to_minor( $total, $currency ).
	sprintf( 'Order #%d', $order_id )
);
// int = credited, null = already credited, false = the write failed (retry later).
```

Gate every "buy" button on the SDK's answer, and hook your plugin's on/off switch into it:

```php
if ( \Wbcom\Credits\Credits::can_purchase( 'my-plugin' ) ) { /* show Buy credits */ }

add_filter( 'wbcom_credits_checkout_enabled', function ( $enabled, $slug ) {
	return 'my-plugin' === $slug ? $enabled && my_plugin_monetization_on() : $enabled;
}, 10, 2 );
```

`Credits::purchase_paths()` says why a member cannot buy (no gateway, no mapping), for an owner-facing hint. `Credits::mapped_offers()` lists mapped products with their buy links.

---

## 6. Charge for things

Pick one pattern per thing you sell. All three check the balance **under the user's lock** (a MySQL named lock plus a locking read), so two requests at once cannot overspend.

### 6a. Let the SDK run it: consumers

For "an item is submitted, held while it waits, then approved or rejected", declare a consumer and fire your own actions:

```php
'consumers' => array(
	array(
		'id'        => 'listing',
		'label'     => 'Listing',
		'cost'      => static fn ( $item_id ) => my_plugin_listing_price( $item_id ), // Or a number.
		'hold_on'   => 'my_plugin_listing_submitted',   // do_action( ..., $post_id )
		'deduct_on' => 'my_plugin_listing_approved',
		'refund_on' => 'my_plugin_listing_rejected',
	),
),
```

The item's author pays. In money mode the cost is an amount of money and may have decimals (`2.5` = 2.50; 1.9.4+). A second submit does not hold twice. A rejected item's hold is released. An approved item is charged once, however often the event fires. When you need the result, call the methods directly: `Registry::instance()->consumer( 'my-plugin', 'listing' )->reserve_item( $id )` returns false when the author cannot afford it. `reprice_item()` charges or refunds the difference when an item moves to another tier.

### 6b. Hold, then settle or release (your own approval flow)

```php
use Wbcom\Credits\Credits;

$hold_id = Credits::try_hold( 'my-plugin', $user_id, $ledger_units, $item_id, 'Booking #' . $id );
if ( false === $hold_id ) {
	// Balance too low (or the account is busy): tell the user, charge nothing.
}
// Store $hold_id with your item.

Credits::settle_hold( 'my-plugin', $user_id, $hold_id );   // Approved: charges what was held.
Credits::release_hold( 'my-plugin', $user_id, $hold_id );  // Rejected: gives it back.
Credits::cancel_hold_by_id( 'my-plugin', $user_id, $hold_id ); // Withdrawn before review.
```

Each works once. Settling a settled hold, or releasing a settled one, returns false and changes nothing.

### 6c. Charge now (no approval step)

For a click, an impression, a renewal:

```php
$row = Credits::spend( 'my-plugin', $user_id, $ledger_units, $item_id, 'Renewal', 'sub:' . $sub_id );
if ( false === $row ) {
	// Short of funds, or the lock timed out.
}
```

`spend( ..., $allow_overdraft = true )` exists only for compensating entries (taking back something already granted). It is not for new spending.

### If you check something of your own first

If a charge depends on your own data too (a quota, a stock count), do the check and the charge inside the same lock:

```php
Credits::with_user_lock( 'my-plugin', $user_id, function () use ( $user_id ) {
	if ( ! my_plugin_quota_left( $user_id ) ) {
		return false;
	}
	return Credits::spend( 'my-plugin', $user_id, 100 );
} );
```

### Old calls

`hold()` (no balance check), `deduct( $item_id )` and `cancel_hold( $item_id )` still work for existing code. `deduct()` returns false when the item has no open hold. New code uses 6a to 6c.

---

## 7. Give money back

| Situation | Call |
|---|---|
| Undo a hold | `release_hold()` / `cancel_hold_by_id()` |
| Give money back for an item (your own refund), without a "credits added" purchase event | `Credits::credit( $slug, $user_id, $ledger_units, $item_id, $note, 'refund', $reference )`; fires `wbcom_credits_credited` |
| Release a hold, or refund with the item's hold lifecycle | `Credits::refund( $slug, $user_id, $ledger_units, $item_id, $note )`, or `refund_money()` in money mode |
| Admin correction, either direction | `Credits::adjust( $slug, $user_id, $signed_units, $note )` |
| Gateway refund (Stripe / PayPal / WooCommerce) | Nothing: the SDK revokes it, capped at what is unspent |

---

## 8. Show and report

```php
$balance = Credits::get_balance( 'my-plugin', $user_id );          // Ledger units.
$display = Credits::is_money( 'my-plugin' )
	? Credits::balance_money( 'my-plugin', $user_id )              // 12.5
	: $balance;

$history = Credits::query_ledger( 'my-plugin', array(
	'user_id' => $user_id,
	'reason'  => array( 'purchase', 'spend', 'refund', 'gateway_refund' ),
	'since'   => get_gmt_from_date( '2026-09-01 00:00:00' ),        // Site time -> UTC.
	'limit'   => 20,
	'offset'  => 0,
) );
$total_spent = -Credits::sum_ledger( 'my-plugin', array( 'user_id' => $user_id, 'reason' => 'spend' ) );

// A list page: balance per user for a whole page of users, one query.
$per_user = Credits::sum_ledger_grouped( 'my-plugin', array( 'user_ids' => $page_user_ids ), 'user_id' );
// $per_user['42'] = array( 'total' => 1250, 'count' => 7 )

// An admin screen's tabs: rows and totals per reason.
$per_reason = Credits::sum_ledger_grouped( 'my-plugin', array( 'since' => $utc_since ) );

$row = Credits::get_ledger_row( 'my-plugin', $ledger_id ); // One row, or null.
```

- Every row has a `reason`: `purchase`, `topup`, `hold`, `hold_release`, `spend`, `refund`, `gateway_refund`, `admin_adjust` or `expiry`. Rows written before 1.9.0 have none.
- Every row also has a `reference` (order, session or event id) and a `hold_id` (the hold that a settle or release closes).
- `created_at` is UTC. Show it with `get_date_from_gmt( $row->created_at )` or `wp_date()`, and convert a date filter to UTC before passing it.
- Page any list: `count_ledger_rows()` gives the total, and `query_ledger()` returns at most 500 rows per call.
- Do not query `{prefix}_credit_ledger` yourself. If you need a report the API lacks, add it to the SDK first.

---

## 9. React to events

| Action | Arguments | Use it for |
|---|---|---|
| `wbcom_credits_topped_up` | slug, user_id, amount, note, ledger_id | "Credits added" email |
| `wbcom_credits_credited` | slug, user_id, amount, item_id, ledger_id, reason | Your own refunds and credits (`Credits::credit()`) |
| `wbcom_credits_purchase_completed` | slug, user_id, log_id | Receipt email (`Receipt::data()`, `Receipt::url()`) |
| `wbcom_credits_held` | slug, user_id, amount, item_id | - |
| `wbcom_credits_deducted` | slug, user_id, amount, item_id | Activity log |
| `wbcom_credits_refunded` | slug, user_id, amount, context | Refund email; `context` carries item_id, ledger_id, reason |
| `wbcom_credits_adjusted` | slug, user_id, signed amount, note | Audit log |
| `wbcom_credits_low` | slug, user_id, balance | "Running low" email; fires once per crossing |
| `wbcom_credits_expired` | slug, user_id, amount, lot_id | Expiry notice |
| `wbcom_credits_gateway_topup` / `wbcom_credits_gateway_refund` | slug, user_id, credits, ledger_id, gateway, session | Gateway-specific bookkeeping |

Amounts are ledger units unless a row says otherwise. Every listener must check `$slug` first: other plugins on the site fire the same actions. Actions fire after the write has committed (1.9.2), so a listener can read the row, and never hears about a write that rolled back.

Filters you are most likely to use: `wbcom_credits_checkout_enabled`, `wbcom_credits_purchase_paths`, `wbcom_credits_cost`, `wbcom_credits_purchase_url`, `wbcom_credits_lock_timeout` (seconds, default 10), and `wbcom_credits_template_path` (override the receipt template).

---

## 10. Test before you ship

Run these on a real site (MySQL, not only a unit-test fake), as a member and as an admin:

- [ ] Buy credits by every route you enabled; each payment credits once, including a repeated webhook.
- [ ] Charge more than the balance: refused, nothing written.
- [ ] Two charges at once for money for one: exactly one passes.
- [ ] Approve, reject, resubmit and re-approve an item: charged once, released once.
- [ ] Refund at the gateway after part of the credits was spent: only the unspent part is revoked.
- [ ] Money mode: a zero-decimal currency (JPY) and a cents amount (12.50) round-trip exactly.
- [ ] Dates in history match the site time zone.
- [ ] With a second plugin bundling an older SDK active, your guard (step 4) hides the UI instead of fataling.

---

## 11. Updating the bundle

1. Read the CHANGELOG between your bundled version and the new tag.
2. Replace `libs/wbcom-credits-sdk/` with the tag's files and update `.bundled-from`.
3. Check the result: `diff -r` against the tag must be empty.
4. Run your suite and the checks in step 10.
5. Update your row in [CONSUMERS.md](../CONSUMERS.md) in the same PR.
6. Remove any `SDK-WORKAROUND` comment the new version makes unnecessary.

One version across the portfolio: when one consumer moves up, the others follow in their next release.
