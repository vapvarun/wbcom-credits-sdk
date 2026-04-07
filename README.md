# Wbcom Credits SDK

Reusable credit engine for WordPress plugins. Append-only ledger, hold/deduct/refund lifecycle, 5 built-in payment adapters, REST API — ready to use in any Wbcom plugin.

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
