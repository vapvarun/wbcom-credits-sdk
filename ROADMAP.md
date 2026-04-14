# Wbcom Credits SDK — Roadmap

## v1.0 (Current) — Launch Ready

**Payment via existing commerce/membership plugins:**
- WooCommerce (one-time products)
- WooCommerce Subscriptions (recurring)
- WooCommerce Memberships
- Paid Memberships Pro
- MemberPress

**Strategy:** Leverage battle-tested plugins the site owner already has. No direct gateway integration needed. Covers 95%+ of WordPress monetization scenarios.

---

## v1.1 (Future) — Direct Gateway Support

Add native Stripe + PayPal gateways so sites without WooCommerce/PMPro/etc. can still sell credits.

### Why

- Many site owners don't want to install WooCommerce just to sell credits
- Direct gateway = simpler checkout (hosted Stripe Checkout / PayPal page)
- Faster conversion (fewer steps than WC cart flow)
- Recurring subscriptions possible via Stripe Subscriptions API

### Scope

**1. StripeGateway (`src/Gateways/Stripe.php`)**

Implements `GatewayInterface` using Stripe Checkout API:
- One-time payments: `checkout.sessions.create` with `mode: 'payment'`
- Subscriptions: `checkout.sessions.create` with `mode: 'subscription'`
- Webhook events handled:
  - `checkout.session.completed` → `Credits::topup()` (one-time)
  - `invoice.payment_succeeded` → `Credits::topup()` (subscription renewal)
  - `customer.subscription.deleted` → optional action (notify user)
- Admin settings: test/live mode toggle, publishable key, secret key, webhook secret
- Price ID mapping: Stripe Price ID → credit amount (stored in `{slug}_stripe_mappings` option)
- Idempotency: track `checkout_session_id` on ledger entries to prevent duplicate topups

**2. PayPalGateway (`src/Gateways/PayPal.php`)**

Implements `GatewayInterface` using PayPal Orders API v2:
- One-time payments: `POST /v2/checkout/orders` with `intent: CAPTURE`
- Subscriptions: `POST /v1/billing/subscriptions` with plan ID
- Webhook events handled:
  - `CHECKOUT.ORDER.APPROVED` + `PAYMENT.CAPTURE.COMPLETED` → `Credits::topup()`
  - `BILLING.SUBSCRIPTION.PAYMENT.COMPLETED` → `Credits::topup()` (renewal)
- Admin settings: sandbox/live mode, client ID, secret, webhook ID
- Product mapping: PayPal Product/Plan ID → credit amount
- Idempotency: track `paypal_order_id` / `paypal_subscription_id`

**3. Admin UI for Direct Gateways**

Add "Payment Gateways" subsection to the consuming plugin's Credits admin page:
- Stripe section (collapsed by default):
  - Enable/disable toggle
  - Mode: Test / Live
  - Publishable Key, Secret Key, Webhook Secret
  - Webhook URL displayed (readonly, copyable): `/wp-json/wbcom-credits/v1/{slug}/stripe-webhook`
  - Credit packages table (Stripe Price ID → Credits)
  - Add Package form
- PayPal section (same structure)
- Detected Providers continues to show existing adapters

**4. REST Endpoints**

Add to SDK REST layer (`src/REST.php`):
- `POST /wbcom-credits/v1/{slug}/checkout/stripe` — create Stripe checkout session, return redirect URL
- `POST /wbcom-credits/v1/{slug}/checkout/paypal` — create PayPal order, return approval URL
- `POST /wbcom-credits/v1/{slug}/stripe-webhook` — receive Stripe events (signature-verified)
- `POST /wbcom-credits/v1/{slug}/paypal-webhook` — receive PayPal events (signature-verified)

**5. Frontend Integration**

Update SDK's Credits Purchase block to show gateway buttons when configured:
- "Pay with Stripe" button → POST to checkout endpoint → redirect to Stripe Checkout
- "Pay with PayPal" button → POST to checkout endpoint → redirect to PayPal approval
- Both hit `Credits::topup()` via webhook on success — exact same lifecycle as WC adapter

**6. Subscriptions Support (v1.2)**

Once one-time works, add recurring:
- Stripe Subscriptions API integration
- PayPal Billing Subscriptions API integration
- Admin UI: toggle per-package "Recurring" flag with interval (monthly/yearly)
- Webhook handlers for renewal events continue crediting user each cycle
- User dashboard shows active subscription + "Cancel" button

### Estimated Effort

| Component | Effort |
|-----------|--------|
| StripeGateway class | 1 day |
| PayPalGateway class | 1 day |
| Webhook signature verification | 0.5 day |
| Admin UI (price mappings) | 1 day |
| REST endpoints | 0.5 day |
| Frontend buttons | 0.5 day |
| Testing (Stripe test mode + PayPal sandbox) | 1 day |
| **Total** | **~5 days** |

### Dependencies

- `stripe/stripe-php` SDK (add via Composer or bundled)
- PayPal REST SDK or raw HTTP (lightweight: use `wp_remote_post`)

### Security Notes

- **Never** log full secret keys or webhook payloads
- Signature verification is **mandatory** before calling `Credits::topup()`
- Idempotency key per transaction (Stripe event ID, PayPal capture ID)
- Rate limit webhook endpoints (default WP REST rate limit is fine)

---

## v2.0 (Later) — Advanced Gateways

- **Razorpay** (for India market)
- **Paddle** (merchant of record — handles VAT)
- **Apple Pay / Google Pay** via Stripe
- **Coinbase Commerce** (crypto payments)

Each implements `GatewayInterface`, follows same pattern as v1.1.

---

## Implementation Notes for v1.1

When implementing, follow this order:

1. **Build StripeGateway first** (Stripe has cleaner API, better docs, easier webhooks)
2. **Test with real Stripe test mode** before starting PayPal
3. **Extract common patterns** (idempotency, webhook verification, mapping lookup) into a base class `AbstractGateway`
4. **Then PayPal** (PayPal's webhook signature verification is trickier — async verification via certificate endpoint)
5. **Document edge cases**: refunds, disputes, subscription pauses, partial captures

**Reference implementations from real WP plugins:**
- Give (Stripe integration): https://github.com/impress-org/givewp
- Simple Pay Pro (Stripe): https://github.com/wpsimplepay
- WP-PayPal plugin examples

**Testing checklist before release:**
- [ ] Test mode one-time payment → credits added
- [ ] Test mode subscription creation → credits added
- [ ] Test mode subscription renewal → credits added on each cycle
- [ ] Webhook signature rejection (invalid signature → 401)
- [ ] Duplicate webhook delivery → no double topup
- [ ] Subscription cancellation → no further topups
- [ ] Refund in Stripe dashboard → option to auto-deduct credits
- [ ] Production keys work identically

---

## Tracking

When v1.1 work starts, create issue: https://github.com/vapvarun/wbcom-credits-sdk/issues/new

Label: `enhancement` + `v1.1` + `direct-gateways`
