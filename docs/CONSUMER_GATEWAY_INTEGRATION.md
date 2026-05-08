# Consumer Gateway Integration Guide

**Audience:** Wbcom plugin developers integrating the SDK's direct payment gateways (Stripe, PayPal, custom) into a consuming plugin so end users can buy credits from the frontend.

**SDK requirement:** v1.2.0+

**Reference implementation:** `wb-listora-pro` (commits `7f336c7` onwards). See `wb-listora-pro/plan/credit-gateways-frontend-purchase.md` for the plugin-specific plan.

---

## What the SDK already gives you

When your plugin registers via `wbcom_credits_sdk_registry`, the SDK auto-creates these REST routes for your slug:

```
POST /wp-json/wbcom-credits/v1/{slug}/checkout/{gateway}    (logged-in user starts checkout)
POST /wp-json/wbcom-credits/v1/{slug}/webhook/{gateway}     (provider hits this; signature is the auth)
POST /wp-json/wbcom-credits/v1/{slug}/refund/{gateway}      (admin-only refund trigger)
```

Built-in gateways:
- **Stripe** (`Wbcom\Credits\Gateways\Stripe`) — Stripe Checkout sessions, signature-verified webhooks, full + partial refunds
- **PayPal** (`Wbcom\Credits\Gateways\PayPal`) — PayPal Orders v2, verify-API signature check, refunds

Built-in safety primitives wired automatically:
- **`Idempotency`** — same provider event delivered twice does NOT double-credit
- **`Pending_Checkouts`** — verifies amount + currency claimed at webhook matches what the user clicked through
- **`Signature_Verifier`** — HMAC + verify-API helpers
- **`Transaction_Log`** — append-only log of every gateway round-trip for refund traceability

What you DO need to ship in your plugin (this guide):
1. **Admin UI** to save gateway credentials (your plugin's settings page).
2. **Frontend block / shortcode** that calls `/checkout/{gateway}` and redirects.
3. **Webhook URL display** so the operator knows what to paste into Stripe / PayPal dashboard.

---

## Step 1 — Settings storage (already handled by the SDK)

The SDK reads gateway settings from option `wbcom_credits_gateway_settings_{slug}`. Shape:

```php
[
    'stripe' => [
        'enabled'         => '1',
        'mode'            => 'test',     // 'test' | 'live'
        'publishable_key' => 'pk_test_...',
        'secret_key'      => 'sk_test_...',
        'webhook_secret'  => 'whsec_...',
        'success_url'     => 'https://your-site.com/credits-success/',
        'cancel_url'      => 'https://your-site.com/credits-cancel/',
    ],
    'paypal' => [
        'enabled'         => '1',
        'mode'            => 'sandbox',  // 'sandbox' | 'live'
        'client_id'       => '...',
        'client_secret'   => '...',
        'webhook_id'      => '...',
        'success_url'     => '...',
        'cancel_url'      => '...',
    ],
]
```

Your plugin's settings save handler writes this option directly. The SDK reads it on demand via `Abstract_Gateway::get_settings_for_slug($slug)`.

**Helper to get the field schema** (for rendering your form):

```php
$gateway = \Wbcom\Credits\Gateways\Gateway_Registry::for_slug( 'your-slug' )->get( 'stripe' );
$fields  = $gateway->get_settings_fields();
// → array of [ 'key' => 'enabled', 'type' => 'bool', 'label' => '…' ], …
```

Each gateway returns its own field list — schema-driven so your form renderer doesn't hard-code per-gateway markup.

---

## Step 2 — Render the admin form

The SDK ships `Wbcom\Credits\Gateways\Admin_Form_Renderer` (v1.2.0+). One call renders the whole form:

```php
\Wbcom\Credits\Gateways\Admin_Form_Renderer::render( 'your-slug' );
```

This:
- Iterates every registered gateway for your slug.
- Pulls each gateway's `get_settings_fields()` schema.
- Loads existing values from `wbcom_credits_gateway_settings_{slug}`.
- Renders a `<form method="post">` with one section per gateway.
- Auto-includes a hidden nonce field (`wbcom_credits_save_gateways_{slug}`).
- Auto-displays the webhook URL for each gateway with a copy button.
- Masks already-saved secrets (shows `••••••••`; only re-typed values overwrite).

Your settings page becomes:

```php
public function render_settings_tab(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    \Wbcom\Credits\Gateways\Admin_Form_Renderer::render( 'your-slug' );
}
```

To override the form markup (e.g. add custom branding, wrap in your tab UI), drop a template at:

```
{your-theme}/wbcom-credits/{your-slug}/admin/gateways-section.php
```

The SDK's `Template::get()` precedence picks it up automatically.

---

## Step 3 — Save handler

The SDK ships `Admin_Form_Renderer::handle_save( 'your-slug' )` — call it on `admin_init` or your save hook:

```php
add_action( 'admin_init', function (): void {
    if ( ! isset( $_POST['wbcom_credits_save_gateways'] ) ) {
        return;
    }
    \Wbcom\Credits\Gateways\Admin_Form_Renderer::handle_save( 'your-slug' );
} );
```

This:
- Verifies nonce + capability.
- Sanitizes each field per its schema type (`bool` → `(bool)`, `text`/`url` → `sanitize_text_field`/`esc_url_raw`, `password` → kept verbatim, only overwritten when non-empty).
- Writes to `wbcom_credits_gateway_settings_{slug}`.
- Adds a `settings_errors()` notice on success.

---

## Step 4 — Frontend purchase trigger

In your block / shortcode / template, render a button that posts to the checkout endpoint. The SDK does NOT ship a JS helper (intentionally — your block already has its own enqueue chain), but here's the canonical shape:

```js
async function buyCredits( gatewayId, credits, priceCents, currency = 'USD' ) {
    try {
        const response = await wp.apiFetch( {
            path: `/wbcom-credits/v1/{slug}/checkout/${ gatewayId }`,
            method: 'POST',
            data: { credits, price_cents: priceCents, currency },
        } );
        if ( response.url ) {
            window.location.href = response.url;
        }
    } catch ( err ) {
        // 401: not logged in
        // 404: gateway not registered
        // 409: gateway not configured (admin must set keys)
        // 502: provider rejected (bad keys, etc.)
        console.error( 'Credit purchase failed:', err );
        showInlineError( err.message || __( 'Could not start checkout — please try again.' ) );
    }
}
```

Replace `{slug}` with your plugin's registered slug (e.g. `wb-listora`).

**Show buttons only for available gateways.** Pass a list from PHP into your block via `wp_localize_script` or a `data-` attr:

```php
$available_gateways = array_map(
    static fn( $gw ) => array(
        'id'    => $gw->get_id(),
        'label' => $gw->get_label(),
    ),
    \Wbcom\Credits\Gateways\Gateway_Registry::for_slug( 'your-slug' )->get_available()
);
wp_localize_script( 'your-block-view', 'YourBlock', array(
    'gateways' => $available_gateways,
    'restBase' => rest_url( 'wbcom-credits/v1/your-slug/checkout/' ),
    'nonce'    => wp_create_nonce( 'wp_rest' ),
) );
```

`Gateway_Registry::get_available()` returns ONLY gateways whose `is_available()` returns true (i.e., enabled + configured) — so your UI never offers a button that would 409.

---

## Step 5 — Pending checkouts (already handled, but worth knowing)

When `/checkout/{gateway}` is hit, the SDK creates a `Pending_Checkouts` row with the `(slug, user_id, credits, price_cents, currency)` tuple keyed by the provider's session ID. When the webhook lands, the SDK cross-checks the provider's reported amount + currency against the pending row. Mismatch → reject.

This means: a malicious client can't lie about credits/price to your checkout endpoint and have the SDK credit a different amount than what the user actually paid. The truth is the provider's webhook payload.

---

## Step 6 — Post-purchase UX

When the provider redirects the user back to your `success_url`, you can render a success page that:
- Confirms the purchase visually.
- Refreshes the user's balance (call `/wp-json/wbcom-credits/v1/{slug}` GET → returns balance).
- Optionally polls for the webhook to land (the redirect happens immediately on success; the webhook may arrive a few seconds later).

If your plugin already has a "credits dashboard" for users, redirect to it. The SDK ships `templates/frontend/balance-widget.php` (v1.2.0+) you can `Template::get('frontend/balance-widget', [ 'slug' => 'your-slug' ])` into any page.

---

## Step 7 — Webhook configuration in the provider dashboard

Tell your operator to:

**Stripe:**
1. Stripe Dashboard → Developers → Webhooks → Add endpoint
2. Endpoint URL: `{your-site-url}/wp-json/wbcom-credits/v1/{slug}/webhook/stripe`
3. Events to send: `checkout.session.completed`, `charge.refunded`
4. Copy the signing secret → paste into your plugin's settings under "Webhook signing secret"

**PayPal:**
1. PayPal Developer Dashboard → My Apps & Credentials → Webhooks
2. Webhook URL: `{your-site-url}/wp-json/wbcom-credits/v1/{slug}/webhook/paypal`
3. Event types: `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.REFUNDED`
4. Copy the webhook ID → paste into your plugin's settings under "Webhook ID"

The `Admin_Form_Renderer` displays the webhook URL prominently with a copy button to make this paste-friendly.

---

## Step 8 — Test the round-trip

1. Set Stripe gateway to TEST mode in your plugin's settings, with test keys.
2. Use Stripe CLI: `stripe listen --forward-to {your-site}/wp-json/wbcom-credits/v1/{slug}/webhook/stripe`
3. Copy the `whsec_...` from `stripe listen` output → paste into your plugin's "Webhook signing secret".
4. Front-end: click "Buy with Stripe" → use test card `4242 4242 4242 4242`, any future expiry, any CVC.
5. Verify in `wp_options.wbcom_credits_*` ledger row appeared.
6. Verify your `/wp-json/{your-slug}/v1/credits` (or equivalent balance endpoint) returns the new balance.
7. Replay the same webhook (Stripe CLI: `stripe trigger ...`) → verify NO double-credit.
8. Initiate refund via admin endpoint → verify negative ledger row + balance updates.

---

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `/checkout/stripe` returns `409 gateway_unavailable` | `Stripe::is_available()` returned false | Check that `enabled=true` and `secret_key` is non-empty in `wbcom_credits_gateway_settings_{slug}`. |
| `/checkout/stripe` returns `502 gateway_error` | Stripe rejected the request | Inspect the message — usually means keys are for the wrong mode (live keys + `mode=test` or vice versa) or expired. |
| Webhook returns `400 invalid_signature` | Webhook signing secret in your plugin's settings doesn't match what Stripe used | Re-copy the secret from Stripe dashboard. If using Stripe CLI, the CLI prints a different secret on each `stripe listen` start — re-paste each time. |
| User pays but balance doesn't update | Webhook never reached you | Check Stripe dashboard → Developers → Events. If "failed", check your endpoint URL is reachable + the signing secret matches. |
| Same payment credited twice | Should be impossible — `Idempotency::seen()` checks per-provider-event-id | If it happens, file a bug. The SDK's idempotency layer is the safety net here. |

---

## Adding a custom gateway

The SDK supports plugin-registered gateways via the `wbcom_credits_register_gateways` action. Build a class extending `Abstract_Gateway`:

```php
class My_Custom_Gateway extends \Wbcom\Credits\Gateways\Abstract_Gateway {
    public function get_id(): string    { return 'mygateway'; }
    public function get_label(): string { return 'My Gateway'; }
    public function is_available(): bool { /* check your settings */ }
    public function get_settings_fields(): array { /* schema for admin UI */ }
    public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD' ): string { /* return a redirect URL */ }
    public function verify_signature( string $raw_body, array $headers ): bool { /* HMAC / verify-API */ }
    public function normalize_event( array $payload ): ?\Wbcom\Credits\Gateways\Gateway_Event { /* map provider's payload to SDK's event DTO */ }
    public function refund( string $slug, string $session_id, ?int $amount_cents = null ): bool { /* call provider's refund API */ }
}

add_action( 'wbcom_credits_register_gateways', function ( $registry, $slug ) {
    $registry->register( new My_Custom_Gateway() );
}, 10, 2 );
```

`Abstract_Gateway` handles the heavy lifting (idempotency, amount cross-check, ledger writes, refund accounting). Your concrete class is ~100-150 lines of provider-specific code.

The new gateway automatically:
- Appears in `Gateway_Registry::for_slug($slug)->get_all()`.
- Gets its own `/checkout/mygateway` and `/webhook/mygateway` routes.
- Renders its settings form via `Admin_Form_Renderer` (no extra wiring needed in your settings page).

---

## Quick checklist for new integrations

- [ ] Plugin registers via `wbcom_credits_sdk_registry` (slug, prefix, consumers).
- [ ] Settings page uses `Admin_Form_Renderer::render( $slug )` + `handle_save( $slug )`.
- [ ] Save handler hooked on `admin_init` checking the form's submit field.
- [ ] Frontend block / shortcode passes available gateways + REST base + nonce to JS.
- [ ] JS calls `/checkout/{gateway}` and redirects on `response.url`.
- [ ] Operator-facing instructions for setting up webhooks in each provider's dashboard.
- [ ] Tested round-trip in TEST mode for each gateway.
- [ ] Idempotency tested (replay same webhook twice).
- [ ] Refund tested (admin endpoint → ledger → balance).

---

## See also

- SDK README: `../README.md`
- SDK ROADMAP: `../ROADMAP.md`
- `Abstract_Gateway` source: `../src/Gateways/Abstract_Gateway.php` (read top docblock for the lifecycle)
- `Webhook_Controller` source: `../src/Gateways/Webhook_Controller.php`
- `Admin_Form_Renderer` source: `../src/Gateways/Admin_Form_Renderer.php`
