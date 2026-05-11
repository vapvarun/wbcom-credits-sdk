# Stripe setup — for site owners

3 steps. ~5 minutes. No coding. No Stripe-Connect or any special tier.

If something doesn't match this guide, copy the error message and ping support before changing settings — Stripe has multiple key types and pasting the wrong one is the #1 cause of failed checkouts.

---

## Step 1 — Get your Stripe API keys

1. Go to [https://dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys) (free Stripe account works).
2. You'll see two pairs of keys: **Test mode** (top toggle) and **Live mode**.
3. Decide what mode you want to start in. **Pick Test mode first** — it lets you place fake orders to confirm the flow works before real money moves.
4. Copy these two values:

   | Stripe field | Looks like | Where it goes in WP admin |
   |---|---|---|
   | **Publishable key** | `pk_test_...` (or `pk_live_...`) | Stripe → Publishable key |
   | **Secret key** | `sk_test_...` (or `sk_live_...`) — click *Reveal* first | Stripe → Secret key |

   **DO NOT paste a `rk_...` (restricted key)** — they don't have permission to create checkout sessions. We need the standard `sk_` key.

5. In WP admin → Credits → Gateways → Stripe, set **Mode** to match (Test or Live), paste the two keys, click *Save*.

---

## Step 2 — Hook up Stripe's webhook

The webhook is how Stripe tells your site "the customer paid — give them credits." Without it, customers pay but never receive credits.

1. In WP admin → Credits → Gateways → Stripe, you'll see a **Webhook URL** field. Copy that URL — it looks like:
   ```
   https://yoursite.com/wp-json/wbcom-credits/v1/<your-slug>/webhook/stripe
   ```
2. Back in Stripe Dashboard → [Developers → Webhooks](https://dashboard.stripe.com/webhooks) → **+ Add endpoint**.
3. Paste the URL into **Endpoint URL**.
4. **Events to send:** click *Select events* and pick exactly these two:
   - `checkout.session.completed`
   - `charge.refunded`

   (Don't pick others — they generate noise and won't be processed.)

5. Click **Add endpoint**. On the next screen, find **Signing secret** → click *Reveal* → copy the value (`whsec_...`).
6. Back in WP admin → Stripe → paste into **Webhook signing secret** → *Save*.

---

## Step 3 — Test it

1. Make sure you're still in **Test mode** in both Stripe + WP admin.
2. On the front-end "Buy credits" page, click any credit-pack button.
3. Stripe Checkout opens. Use Stripe's free test card: **`4242 4242 4242 4242`** with any future expiry, any 3-digit CVC, any 5-digit ZIP.
4. Complete the purchase. You should land back on your site with a success message.
5. Refresh the wallet page — credit balance should match the pack you bought.
6. In Stripe Dashboard → Developers → Webhooks → click your endpoint → check the **Recent attempts** tab. You should see a `200 OK` for `checkout.session.completed`.

If the balance didn't update:
- Webhook tab shows non-200 → secret is wrong. Re-copy the signing secret from Stripe; paste into WP admin again.
- Webhook tab shows nothing → URL is wrong. Re-copy the Webhook URL from WP admin; paste into Stripe.
- Webhook 200 but balance still 0 → check your site's PHP error log around the timestamp. Usually a missing pricing config (see SDK 1.3.0 migration notes).

---

## Going live

When ready for real payments:

1. In Stripe dashboard, toggle to **Live mode**.
2. Copy live keys (`pk_live_...`, `sk_live_...`).
3. Add a **second webhook endpoint** in Live mode (different signing secret than Test).
4. In WP admin, switch Mode to *Live*, paste live keys + live webhook secret, save.

**Both Test and Live keys can be saved at the same time** in the WP admin — the *Mode* toggle picks which set is active. So you can flip back to Test for one-off debugging without re-typing live keys.

---

## What you don't need to do

- ❌ Set up Stripe Connect — that's for marketplaces splitting payments between sellers. Credit checkout is single-seller; standard Stripe is enough.
- ❌ Configure Apple Pay / Google Pay separately — Stripe Checkout auto-detects + offers them when the customer's device supports it.
- ❌ Convert customers to "Saved cards" — Stripe Checkout handles that on its hosted page if you turn on *Customer Portal*, but it's optional.

---

## Common stumbles

| Symptom | Cause | Fix |
|---|---|---|
| "Stripe is not configured" error on click | Secret key empty or `rk_` not `sk_` | Re-paste from Stripe Dashboard → API keys, use the standard secret |
| Customer paid but no credits | Webhook URL wrong, or signing secret wrong | Compare both fields; Stripe → Webhook → "Recent attempts" tells you which |
| `signature verification failed` in logs | Signing secret mismatch | The signing secret regenerates if you delete + recreate the endpoint. Re-copy from Stripe after any edit. |
| Test card declined | Stripe is in Live mode but you used a test card | Switch Stripe Dashboard toggle to Test mode (top right) |
| Webhook hangs / times out | Your site is HTTP, Stripe requires HTTPS in production | Move site to HTTPS before going live |
