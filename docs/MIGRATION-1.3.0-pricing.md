# Migration: SDK 1.3.0 server-authoritative pricing

**Audience:** maintainers of consumer plugins that bundle the Wbcom Credits SDK and use the **direct-pay gateways** (Stripe and/or PayPal). Plugins that only use the WooCommerce / WC Subscriptions / PMPro / MemberPress adapters are unaffected — those paths were already server-authoritative.

**Required action:** every consumer plugin that exposes a "Buy credits" button hitting `/checkout/{gateway}` MUST register a `pricing` config at `Registry::register()` before bundling SDK 1.3.0. Without it, the checkout endpoint returns `503 pricing_not_configured` and customers cannot buy credits.

**Why:** issue #2 — SDK 1.2.x accepted client-supplied `price_cents`, letting any logged-in user POST `credits=10000` + `price_cents=1` and walk away with 10,000 credits for 1¢. SDK 1.3.0 closes the hole by ignoring client `price_cents` and computing it server-side from a registered pricing config. The vulnerable mode is deliberately broken at the SDK boundary.

---

## Pick a mode

Two server-authoritative modes — most plugins want **pack mode**.

### Pack mode (preferred — 1-click hosted-checkout UX)

Consumer registers a fixed set of credit packs. Frontend buttons map 1:1 to a pack. The button POSTs `pack_id`, the SDK looks up the tuple, the customer is redirected to Stripe/PayPal with the server-known price.

```php
$registry->register([
    'slug'    => 'wbam-pro',
    'prefix'  => 'wbam',
    'pricing' => [
        'currency' => 'USD',
        'packs'    => [
            'starter'  => [ 'credits' => 100,  'price_cents' => 1000  ], // $10  → 100 credits
            'pro'      => [ 'credits' => 500,  'price_cents' => 4500  ], // $45  → 500 credits (10% off)
            'business' => [ 'credits' => 2000, 'price_cents' => 16000 ], // $160 → 2000 credits (20% off)
        ],
    ],
    // ... your other Registry::register() keys.
]);
```

Frontend button:

```html
<button class="buy-credits" data-pack-id="pro">Buy 500 credits — $45</button>
```

Frontend handler:

```js
fetch('/wp-json/wbcom-credits/v1/wbam-pro/checkout/stripe', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: JSON.stringify({ pack_id: button.dataset.packId }),
}).then(r => r.json()).then(({ url }) => location.href = url);
```

Notice the body has **no `price_cents` field**. Pass `pack_id`, nothing else. If the body did include `price_cents`, the SDK would silently ignore it.

### Callback mode (for adjustable-quantity flows)

If your UX is a quantity-input rather than preset packs ("buy any number from 50 to 5000 credits, 10¢ each"), register a callback and bounds:

```php
$registry->register([
    'slug'    => 'wbam-pro',
    'prefix'  => 'wbam',
    'pricing' => [
        'currency'               => 'USD',
        'credits_to_price_cents' => static fn ( int $credits ): int => $credits * 10,
        'min_credits'            => 50,
        'max_credits'            => 5000,
    ],
    // ... other keys.
]);
```

Frontend POSTs `{ credits: 250 }`. SDK enforces `50 <= credits <= 5000`, calls the callback, uses the result.

Both modes can coexist if you register **both** keys — the SDK prefers `pack_id` when present, falls back to `credits` + callback otherwise.

---

## Checklist before shipping SDK 1.3.0 in your plugin

- [ ] Add a `pricing` key to your `Registry::register()` call.
- [ ] Decide pack mode or callback mode (pack mode preferred unless you have a strong reason).
- [ ] If pack mode: list your packs with explicit `credits` + `price_cents` per pack. Make `pack_id` keys human-readable (`starter`, not `pack-1`).
- [ ] If callback mode: enforce sane `min_credits` / `max_credits` bounds. Don't let a malicious client request 1 credit (1¢ — Stripe charges a 30¢ fee, you lose money) or 1 billion credits (mid-transaction PHP int overflow).
- [ ] Update your "Buy credits" frontend to send `pack_id` (or `credits`) — **stop sending `price_cents`**. The field is now ignored, but bandwidth is still bandwidth.
- [ ] Re-render any saved "buy" links / nonces — old hardcoded URLs with `price_cents` query params will simply have that param dropped.
- [ ] Run your test pack and confirm:
  - Checkout 200s with valid pack_id.
  - Checkout 404s with unknown pack_id.
  - Checkout 503s if you forget to register pricing.
  - The credit-grant after successful payment matches what the pack says (not what the client posted).
- [ ] Update your plugin's CHANGELOG to note "uses SDK 1.3.0 server-authoritative pricing; clients can no longer set their own price."

---

## Error codes

| HTTP | `code` | Cause |
|---|---|---|
| 404 | `plugin_not_registered` | Slug isn't in `Registry::instance()`. Bootstrap order issue. |
| 503 | `pricing_not_configured` | Consumer didn't register `pricing` at all. Migration required. |
| 404 | `unknown_pack` | Client sent `pack_id` that doesn't exist in `packs`. |
| 500 | `invalid_pack` | Pack registered with `credits <= 0` or `price_cents <= 0`. |
| 400 | `missing_input` | Request has neither `pack_id` nor `credits`. |
| 503 | `callback_not_configured` | Consumer sent `credits` in request but no `credits_to_price_cents` registered. |
| 400 | `credits_out_of_bounds` | `credits` outside `min_credits` / `max_credits`. |
| 500 | `invalid_callback_result` | Callback returned `price_cents <= 0`. |

---

## What did NOT change

- The webhook endpoint, signature verification, idempotency tracking, and refund handling are unchanged. SDK 1.3.0 only re-shapes the inputs to `create_checkout()`.
- The Stripe / PayPal credentials your site owner configures don't change.
- The post-payment `Credits::topup()` call still receives the (now server-authoritative) credit count and tops up exactly that many — no math drift.
- Pending_Checkouts / Transaction_Log tables don't change shape.

---

## Why this isn't a major-version bump

The change is breaking for consumer plugins that haven't registered pricing yet. We're calling it a minor (1.3.0, not 2.0.0) because:

1. The behavior change closes a security hole, not a feature change. Consumers that adopted the SDK *correctly* (deferred to admin-side rate config) were already not at risk; they just have to formalize their config in `Registry::register()` now.
2. The SDK is pre-1.0 GA semver-wise (no published "stable" contract yet); these tightenings are expected during the early portfolio rollout.
3. Bundling 1.3.0 without migration produces a clean 503 error, not silent corruption — easy to surface in QA.

If you maintain a consumer plugin and want to defer adopting 1.3.0 until your next release window, pin the SDK to `~1.2.0` in your `composer.json` or vendor-copy script — but understand that anyone running your plugin on a site where another plugin upgrades the SDK will trigger the multi-version coexistence loader to pick 1.3.0 anyway. **Best practice: get every consumer plugin onto 1.3.0 in a coordinated wave**, as described in `PORTFOLIO-PLAN.md` Phase 4.
