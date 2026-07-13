# Wiring native hosted checkout in a consuming plugin

**Audience:** maintainers of any plugin that bundles the Wbcom Credits SDK and wants a "Buy Credits" button that sends the user to a hosted Stripe/PayPal checkout and returns them to the same page.

The SDK provides the whole mechanism (checkout REST route, pricing resolver, gateways, webhook, the JS helper, and the admin pack-editor). The consuming plugin only wires: pricing config, where the admin editor renders, and where the button appears. Payment confirmation is **webhook-driven** — the browser redirect is UX only.

## 1. Register pricing config

At `Registry::register()` time (your `wbcom_credits_sdk_registry` callback), add a `pricing` key. Both modes can coexist — packs (fixed tiers) and a custom-amount callback:

```php
'pricing' => array(
    'currency'               => 'USD',
    'packs'                  => array(
        'pack_0' => array( 'credits' => 10,  'price_cents' => 2900 ),
        'pack_1' => array( 'credits' => 50,  'price_cents' => 9900 ),
    ),
    'min_credits'            => 1,
    'max_credits'            => 500,
    'credits_to_price_cents' => static function ( int $credits ): int {
        return $credits * 198; // your per-credit rate in cents
    },
),
```

Source these values from an option so the site owner controls them (see step 2). Without a `pricing` key, `POST /{slug}/checkout/{gateway}` returns `503 pricing_not_configured`.

## 2. Render the admin pack-editor

On your settings page, let the owner define packs + custom-amount, and register the option with the SDK sanitizer:

```php
add_action( 'admin_init', function () {
    register_setting( 'your_group', 'your_pricing_option', array(
        'sanitize_callback' => array( '\\Wbcom\\Credits\\Gateways\\Pack_Admin_Renderer', 'sanitize' ),
    ) );
} );

// In the settings form:
\Wbcom\Credits\Gateways\Pack_Admin_Renderer::render( 'your_pricing_option' );
```

`sanitize()` returns the exact shape step 1 needs — read `your_pricing_option` back when building the `pricing` array.

Gateway API keys have their own reusable editor: `\Wbcom\Credits\Gateways\Admin_Form_Renderer::render( $slug )`.

## 3. Render the Buy button and call the helper

Enqueue the helper where your button renders, and gate on gateway availability:

```php
wp_enqueue_script( 'wbcom-credits-checkout' );

$reg = \Wbcom\Credits\Gateways\Gateway_Registry::for_slug( $slug );
foreach ( $reg->get_all() as $gw ) {
    if ( $gw->is_available() ) { /* show a button/option for $gw->get_id() */ }
}
```

Client side:

```js
window.wbcomCreditsCheckout({
    slug: 'your-slug',
    gateway: selectedGatewayId,   // 'stripe' | 'paypal'
    pack_id: 'pack_0',            // OR omit and pass credits:
    // credits: 75,
    returnUrl: window.location.href
}).catch(function (e) { /* show e.message inline */ });
```

The SDK computes the price server-side (client-supplied prices are ignored), creates the hosted session, and the helper redirects the browser to it.

## 4. Handle the return (webhook is the source of truth)

The gateway appends `?wbcom_credits=success|cancel` (success also carries `session_id`) to your `returnUrl`. On return:

- `success` → show "Payment received — confirming your credits…" and **read the live balance** (`Credits::get_balance()` / your balance endpoint). Do NOT assert credited from the query param. The signed webhook (`POST /{slug}/webhook/{gateway}`) is what actually credits the ledger; it may land a moment after the redirect, so poll or refresh.
- `cancel` → "Checkout canceled — you were not charged."

If the user closes the tab before the redirect, the webhook still credits them.
