# Wbcom Credits SDK

Reusable credit engine for WordPress plugins. Append-only ledger, hold/deduct/refund lifecycle, 5 built-in payment adapters (WooCommerce, WC Subscriptions, WC Memberships, PMPro, MemberPress), 2 built-in **direct payment gateways** (Stripe, PayPal) with full refund support, REST API — ready to use in any Wbcom plugin.

## Quick Start — 5 Lines

```php
// In your plugin's main file, BEFORE including the SDK:
add_action( 'wbcom_credits_sdk_registry', function ( $registry ) {
    $registry->register( [
        'slug'      => 'my-plugin',
        'prefix'    => 'mp',
        'version'   => MY_PLUGIN_VERSION,
        'file'      => __FILE__,
        'user_type' => 'member',
        'consumers' => [
            [
                'id'        => 'blog_post',
                'label'     => 'Blog Post',
                'cost'      => 1,
                'hold_on'   => 'mp_post_submitted',
                'deduct_on' => 'mp_post_approved',
                'refund_on' => 'mp_post_rejected',
            ],
        ],
        'settings' => [
            'low_threshold'       => 3,
            'purchase_url'        => '/buy-credits/',
            'admin_settings_hook' => 'mp_admin_settings_tabs',
        ],
    ] );
} );

// Include the SDK (conditional — handles version conflicts)
if ( file_exists( __DIR__ . '/vendor/wbcom-credits-sdk/wbcom-credits-sdk.php' ) ) {
    require_once __DIR__ . '/vendor/wbcom-credits-sdk/wbcom-credits-sdk.php';
}
```

That's it. The SDK auto-creates the DB table, wires the hold/deduct/refund hooks, registers REST endpoints, and initializes payment adapters.

---

## Backend Integration

### Reading Balance

```php
use Wbcom\Credits\Credits;

// Get balance for current user
$balance = Credits::get_balance( 'my-plugin', get_current_user_id() );

// Check if credits are enabled
if ( Credits::is_enabled( 'my-plugin' ) ) {
    // Show credit-related UI
}

// Get cost for a consumer item
$cost = Credits::get_cost( 'my-plugin', 'blog_post', $post_id );

// Get purchase URL
$url = Credits::get_purchase_url( 'my-plugin' );
```

### Manual Credit Operations

```php
use Wbcom\Credits\Credits;

// Admin topup
Credits::topup( 'my-plugin', $user_id, 50, 'Manual top-up by admin' );

// Admin adjustment (positive or negative)
Credits::adjust( 'my-plugin', $user_id, -10, 'Penalty for violation' );

// Place a hold manually
Credits::hold( 'my-plugin', $user_id, 5, $item_id, 'Premium feature access' );

// Deduct (settles a hold)
Credits::deduct( 'my-plugin', $user_id, 5, $item_id, 'Feature access confirmed' );

// Refund a hold
Credits::refund( 'my-plugin', $user_id, 5, $item_id, 'Access denied — credits returned' );

// Cancel an unconsumed hold (hard delete)
Credits::cancel_hold( 'my-plugin', $user_id, $item_id );
```

### Transaction History

```php
use Wbcom\Credits\Credits;

$entries = Credits::get_ledger( 'my-plugin', $user_id, 50, 0 );

foreach ( $entries as $entry ) {
    printf(
        '%s: %+d credits (%s) — %s',
        $entry->created_at,
        $entry->amount,
        $entry->entry_type,  // topup, hold, deduction, refund
        $entry->note
    );
}
```

### Pre-Submission Credit Gate

```php
// In your REST endpoint or form handler:
$cost    = Credits::get_cost( 'my-plugin', 'blog_post', $post_id );
$balance = Credits::get_balance( 'my-plugin', $user_id );

if ( $cost > 0 && $balance < $cost ) {
    return new WP_Error(
        'insufficient_credits',
        sprintf( 'You need %d credits but only have %d.', $cost, $balance ),
        array( 'status' => 402 )
    );
}
```

### Hooks — Listen for Credit Events

```php
// After credits are topped up (e.g., send a confirmation email)
add_action( 'wbcom_credits_topped_up', function ( $slug, $user_id, $amount, $note ) {
    if ( 'my-plugin' !== $slug ) return;
    // Send email, update UI, etc.
}, 10, 4 );

// Low balance warning
add_action( 'wbcom_credits_low', function ( $slug, $user_id, $balance ) {
    if ( 'my-plugin' !== $slug ) return;
    // Send low-balance email
}, 10, 3 );

// After deduction (item was approved)
add_action( 'wbcom_credits_deducted', function ( $slug, $user_id, $amount, $item_id ) {
    if ( 'my-plugin' !== $slug ) return;
    // Update post status, send notification
}, 10, 4 );
```

### Filters — Customize Behavior

```php
// Dynamic cost based on item properties
add_filter( 'wbcom_credits_cost', function ( $cost, $slug, $consumer_id, $item_id ) {
    if ( 'my-plugin' === $slug && 'blog_post' === $consumer_id ) {
        // Featured posts cost 3x
        if ( get_post_meta( $item_id, '_is_featured', true ) ) {
            return $cost * 3;
        }
    }
    return $cost;
}, 10, 4 );

// Override purchase URL
add_filter( 'wbcom_credits_purchase_url', function ( $url, $slug ) {
    if ( 'my-plugin' === $slug ) {
        return home_url( '/pricing/' );
    }
    return $url;
}, 10, 2 );
```

---

## Frontend Integration (Interactivity API)

### Passing Credit Data to Blocks

```php
// In your block's render.php:
$user_id = get_current_user_id();

wp_interactivity_state(
    'my-plugin/credit-widget',
    array(
        'balance'     => \Wbcom\Credits\Credits::get_balance( 'my-plugin', $user_id ),
        'cost'        => \Wbcom\Credits\Credits::get_cost( 'my-plugin', 'blog_post', 0 ),
        'purchaseUrl' => \Wbcom\Credits\Credits::get_purchase_url( 'my-plugin' ),
        'enabled'     => \Wbcom\Credits\Credits::is_enabled( 'my-plugin' ),
    )
);
```

### Using in Interactivity API Store

```js
// In your block's view.js:
import { store, getContext } from '@wordpress/interactivity';

const { state, actions } = store( 'my-plugin/credit-widget', {
    state: {
        get hasEnoughCredits() {
            return state.balance >= state.cost;
        },
        get insufficientMessage() {
            return `You need ${state.cost} credits but have ${state.balance}.`;
        },
    },
    actions: {
        async refreshBalance() {
            const res = await fetch( '/wp-json/wbcom-credits/v1/my-plugin/balance' );
            const data = await res.json();
            state.balance = data.balance;
        },
    },
} );
```

### Directives in Templates

```html
<!-- Show credit balance -->
<span data-wp-text="state.balance"></span>

<!-- Show/hide based on credits -->
<div data-wp-show="state.hasEnoughCredits">
    <button data-wp-on--click="actions.submitPost">Submit Post (costs 1 credit)</button>
</div>

<div data-wp-show="!state.hasEnoughCredits">
    <p data-wp-text="state.insufficientMessage"></p>
    <a data-wp-bind--href="state.purchaseUrl">Buy Credits</a>
</div>
```

---

## REST API Endpoints

All endpoints are prefixed with `/wbcom-credits/v1/{slug}/`.

### GET /balance

Returns the current user's balance (or any user if admin).

```
GET /wp-json/wbcom-credits/v1/my-plugin/balance
GET /wp-json/wbcom-credits/v1/my-plugin/balance?user_id=42
```

Response:
```json
{ "user_id": 42, "balance": 15, "enabled": true }
```

### GET /history

Returns paginated ledger entries.

```
GET /wp-json/wbcom-credits/v1/my-plugin/history?limit=20&offset=0
```

Response:
```json
{
    "user_id": 42,
    "balance": 15,
    "entries": [
        { "id": 1, "user_id": 42, "item_id": 0, "entry_type": "topup", "amount": 20, "note": "WooCommerce Order #123", "created_at": "2026-04-07 10:00:00" },
        { "id": 2, "user_id": 42, "item_id": 456, "entry_type": "hold", "amount": -5, "note": "Blog Post — credits held", "created_at": "2026-04-07 11:00:00" }
    ]
}
```

### POST /topup (Admin only)

```
POST /wp-json/wbcom-credits/v1/my-plugin/topup
{ "user_id": 42, "amount": 10, "note": "Bonus credits" }
```

Response:
```json
{ "user_id": 42, "adjusted": 10, "new_balance": 25 }
```

---

## Payment Adapters

The SDK includes 5 built-in adapters that automatically top up credits when users purchase products or activate memberships:

| Adapter | Plugin Required | Trigger |
|---------|----------------|---------|
| WooCommerce | WooCommerce | Order completed/processing |
| WooCommerce Subscriptions | WC Subscriptions | Subscription active + renewals |
| WooCommerce Memberships | WC Memberships | Membership status → active |
| Paid Memberships Pro | PMPro | Level assigned + renewal payments |
| MemberPress | MemberPress | Transaction completed |

### Setting Up Mappings

Credit mappings link products/levels to credit amounts. Stored in `{slug}_credit_mappings` option:

```php
// Programmatic mapping example:
update_option( 'my-plugin_credit_mappings', [
    [ 'adapter' => 'woocommerce', 'item_id' => 123, 'credits' => 10 ],
    [ 'adapter' => 'woocommerce', 'item_id' => 456, 'credits' => 50 ],
    [ 'adapter' => 'pmpro',       'item_id' => 1,   'credits' => 20 ],
] );
```

### Adding Custom Adapters

```php
use Wbcom\Credits\Adapters\AdapterInterface;

class MyCustomAdapter implements AdapterInterface {
    public function get_id(): string { return 'my-gateway'; }
    public function get_label(): string { return 'My Gateway'; }
    public function is_available(): bool { return class_exists( 'MyGateway' ); }
    public function register_hooks( string $slug ): void {
        // Hook into your gateway's payment completion event
    }
    public function get_mappable_items(): array {
        return [ 1 => 'Basic Pack', 2 => 'Pro Pack' ];
    }
}

add_action( 'wbcom_credits_register_adapters', function ( $registry, $slug ) {
    $registry->register( new MyCustomAdapter() );
}, 10, 2 );
```

---

## Direct Payment Gateways (since 1.2.0)

For sites that don't run WooCommerce / PMPro / MemberPress, the SDK ships **direct gateways** so users can buy credits with Stripe Checkout or PayPal — without any e-commerce plugin in between. Both gateways support **provider-initiated refunds** (admin clicks "Refund" in Stripe/PayPal dashboard → SDK debits credits) and **SDK-initiated refunds** (admin calls `Gateway::refund()` → SDK calls provider API → provider sends refund webhook → SDK debits credits).

| Gateway | Mode | Refund | Subscriptions |
|---------|------|--------|---------------|
| Stripe Checkout | Test / Live | ✅ Full + partial | ⏳ v1.3 |
| PayPal Orders v2 | Sandbox / Live | ✅ Full + partial | ⏳ v1.3 |

### How it works (one diagram)

```
       ┌──────────────────────┐    POST /checkout/{gateway}    ┌─────────────────────┐
       │ Consumer plugin's UI │ ─────────────────────────────▶ │ SDK Webhook_Ctrl    │
       └──────────────────────┘                                 └────────┬────────────┘
                                                                         │ create_checkout()
                                                                         ▼
       ┌──────────────────────┐    Hosted checkout page        ┌─────────────────────┐
       │ Stripe / PayPal      │ ◀───────────────────────────── │ Pending_Checkouts   │
       │ payment page         │                                │ stores expected $/¢ │
       └──────────┬───────────┘                                 └─────────────────────┘
                  │ User pays
                  ▼
       ┌──────────────────────┐    Webhook event               ┌─────────────────────┐
       │ Stripe / PayPal      │ ─────────────────────────────▶ │ Webhook_Controller  │
       │ webhook              │                                │ verify_signature()  │
       └──────────────────────┘                                │ normalize_event()   │
                                                                 │ Idempotency check   │
                                                                 │ Cross-check $/¢      │
                                                                 │ Credits::topup()    │
                                                                 │ Transaction_Log    │
                                                                 └─────────────────────┘
```

### REST endpoints (per consuming slug)

```
POST  /wbcom-credits/v1/{slug}/checkout/stripe   { credits, price_cents, currency }
POST  /wbcom-credits/v1/{slug}/checkout/paypal   { credits, price_cents, currency }
POST  /wbcom-credits/v1/{slug}/webhook/stripe    (signed; provider-initiated)
POST  /wbcom-credits/v1/{slug}/webhook/paypal    (signed; provider-initiated)
POST  /wbcom-credits/v1/{slug}/refund/{gateway}  { session_id, amount_cents? } (admin)
```

### Settings (stored in `wbcom_credits_gateway_settings_{slug}` option)

```php
[
    'stripe' => [
        'enabled'        => true,
        'mode'           => 'test',          // or 'live'
        'publishable_key'=> 'pk_test_...',
        'secret_key'     => 'sk_test_...',
        'webhook_secret' => 'whsec_...',
        'success_url'    => '/credits/thanks/',
        'cancel_url'     => '/credits/cancel/',
    ],
    'paypal' => [
        'enabled'       => true,
        'mode'          => 'sandbox',         // or 'live'
        'client_id'     => '...',
        'client_secret' => '...',
        'webhook_id'    => '8XL12345...',
    ],
]
```

### Adding a custom gateway

The shared base does the heavy lifting — extend `Abstract_Gateway` and only implement the provider-specific methods:

```php
use Wbcom\Credits\Gateways\Abstract_Gateway;
use Wbcom\Credits\Gateways\Gateway_Event;

final class Razorpay extends Abstract_Gateway {
    public const ID = 'razorpay';
    public function get_id(): string { return self::ID; }
    public function get_label(): string { return 'Razorpay'; }
    public function is_available(): bool { /* check creds */ }
    public function get_settings_fields(): array { /* admin UI */ }

    public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD' ): string {
        // Call Razorpay Orders API, store via Pending_Checkouts::put(), return URL.
    }
    public function verify_signature( string $raw_body, array $headers ): bool {
        // Verify via Signature_Verifier (or Razorpay's HMAC).
    }
    public function normalize_event( array $payload ): ?Gateway_Event {
        // Translate payment.captured / payment.refunded → Gateway_Event.
    }
    public function refund( string $slug, string $session_id, ?int $amount_cents = null ): bool {
        // Call provider refund API.
    }
}

add_action( 'wbcom_credits_register_gateways', function ( $registry ) {
    $registry->register( new Razorpay() );
}, 10, 1 );
```

The orchestration in `Abstract_Gateway::handle_webhook()` covers idempotency, amount/currency cross-check, top-up, refund accounting, `Transaction_Log` writes, and the `wbcom_credits_gateway_topup` / `wbcom_credits_gateway_refund` action hooks — every gateway gets all of that for free.

### Refund accounting

When a refund webhook arrives, the SDK:

1. Looks up the original checkout in `Transaction_Log`.
2. Clamps the refund amount so total refunded ≤ amount captured.
3. Prorates credits to revoke: `floor( orig_credits × refund_amount / orig_amount )`.
4. Calls `Credits::adjust( $slug, $user, -$revoked, 'gateway:stripe:refund:cs_xxx' )`.
5. Appends a `KIND_REFUND` row in `Transaction_Log` linked to the parent checkout.
6. Increments `refunded_cents` on the parent so partial refunds compose correctly.

A second refund event for the same `event_id` is a no-op (idempotency), and a refund larger than the remaining capturable amount is silently clamped — a misbehaving provider cannot revoke more credits than the user actually bought.

### Transaction Log table

`{wp_prefix}{plugin_prefix}_credit_gateway_log` records every checkout and refund event. Columns: `id, slug, gateway, kind, session_id, event_id, user_id, credits, amount_cents, refunded_cents, currency, ledger_id, parent_id, created_at`. Indexed by `(slug, gateway, session_id)` and `(slug, gateway, event_id)` so support staff can find any payment in O(1).

---

## Database

The SDK creates one table per consuming plugin: `{wp_prefix}{plugin_prefix}_credit_ledger`

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT UNSIGNED | Auto-increment primary key |
| user_id | BIGINT UNSIGNED | WordPress user ID |
| item_id | BIGINT UNSIGNED | Associated post/item ID (0 if n/a) |
| entry_type | VARCHAR(20) | topup, hold, deduction, refund |
| amount | INT | Signed — positive for credits in, negative for out |
| note | VARCHAR(255) | Human-readable description |
| created_at | DATETIME | Auto-timestamp |

**Balance = SUM(amount) WHERE user_id = X**

The ledger is append-only. The only DELETE operation is `cancel_hold()` for unconsumed holds.

### Schema contract (since 1.3.0)

The SDK ships **one canonical schema**. Consumer plugins MUST NOT pre-empt `Ledger::maybe_create_table()` with their own `CREATE TABLE` and MUST NOT rename columns.

- Use `user_id` for the WP user ID, even when your domain calls them "employer", "attendee", "member", or "customer". Expose the domain-readable name in your plugin's public API (REST shapes, admin UI, CLI), not in the database column.
- Use `item_id` for the associated entity, even when your domain calls it "post", "booking", "course", or "subscription".
- Add columns via your own join tables. Do not extend `*_credit_ledger`.

The contract is enforced by `tests/Ledger/SchemaContractTest.php` — schema renames surface as CI failures before merge. See `docs/MIGRATION-1.3.0-career-board.md` for an example consumer-side migration.

---

## Version Conflict Prevention

Multiple plugins can bundle different SDK versions. Only the highest version initializes (same pattern as EDD SL SDK):

1. Each bundled copy registers its version via `Versions::register()`
2. `Versions::initialize_latest_version()` calls only the highest version's init callback
3. All plugins share the same `Registry` singleton
4. Safe to bundle alongside other Wbcom plugins that also use the SDK

---

## Use Cases

| Plugin | What costs credits | Consumer ID |
|--------|-------------------|-------------|
| Job Board | Job posting | `job_post` |
| Member Blog | Blog post submission | `blog_post` |
| BuddyPress Polls | Poll creation | `poll` |
| Marketplace | Product listing | `listing` |
| Community | Featured profile | `featured_profile` |
| Learning | Course access | `course_access` |
| Events | Event registration | `event_registration` |

---

## Requirements

- PHP 8.1+
- WordPress 6.5+
- `declare( strict_types=1 )` in all files
