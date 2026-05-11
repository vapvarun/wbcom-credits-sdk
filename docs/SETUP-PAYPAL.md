# PayPal setup — for site owners

3 steps. ~7 minutes. Requires a **PayPal Business account** (free — but a Personal PayPal account cannot accept API payments; PayPal will refuse the app credentials).

If you only have a Personal account, upgrade it first at [paypal.com → Account Settings → Account Type → Upgrade to Business](https://www.paypal.com/businessmanage/account/accountAccess).

---

## Step 1 — Create a PayPal app

1. Go to [developer.paypal.com/dashboard](https://developer.paypal.com/dashboard/applications/) and log in with your **Business** PayPal account.
2. **Sandbox** vs **Live** — toggle at the top right. Start in **Sandbox** so you can place fake orders before going live.
3. Click **Apps & Credentials → Create App**.
4. Fill in:
   - **App Name:** anything you'll recognise (e.g. "MySite credits").
   - **Type:** *Merchant* (default).
   - **Sandbox Business Account:** PayPal pre-creates one; leave it selected.
5. Click *Create App*. You're now on the app's credential page.
6. Copy these two values:

   | PayPal field | Looks like | Where it goes in WP admin |
   |---|---|---|
   | **Client ID** | `AeA...` (about 80 chars) | PayPal → Client ID |
   | **Secret key 1** | `EH...` (click *Show* first) | PayPal → Client secret |

7. In WP admin → Credits → Gateways → PayPal: set **Mode** to *Sandbox*, paste Client ID + Client secret, *Save*.

---

## Step 2 — Hook up PayPal's webhook

PayPal won't tell your site when an order completes unless you register a webhook.

1. In WP admin → Credits → Gateways → PayPal you'll see a **Webhook URL** field. Copy it — looks like:
   ```
   https://yoursite.com/wp-json/wbcom-credits/v1/<your-slug>/webhook/paypal
   ```
2. Back in your PayPal app's credential page, scroll to **Webhooks** → **Add Webhook**.
3. Paste the URL into **Webhook URL**.
4. **Event types** — tick exactly these:
   - `CHECKOUT.ORDER.APPROVED`
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.REFUNDED`

   (Skip the rest. Extra events just slow things down without changing behaviour.)

5. Click **Save**. PayPal lists the new webhook with a **Webhook ID** (`WH-...`).
6. Copy the Webhook ID. Paste it into WP admin → PayPal → **Webhook ID** → *Save*.

---

## Step 3 — Test it

1. Make sure both PayPal Dashboard + WP admin are in **Sandbox** mode.
2. PayPal pre-created a **Sandbox Personal account** (the "test buyer") for you — find it under [Sandbox → Accounts](https://developer.paypal.com/dashboard/accounts). Note the email + password. This is the dummy buyer.
3. On the front-end "Buy credits" page, click any credit-pack button.
4. PayPal Checkout opens. Sign in with the **Sandbox Personal** credentials from step 2.
5. Confirm the order. You land back on your site.
6. Refresh the wallet page — credit balance should match.
7. In your PayPal Dashboard → app → **Webhooks** → click the endpoint → check **Sample Notification** to confirm PayPal can reach the URL.

If the balance didn't update:
- Webhook event log shows non-2xx → Webhook ID is wrong. Re-copy from PayPal → app → Webhooks.
- No events shown → URL is wrong, OR your site is HTTP (PayPal requires HTTPS in Live mode; Sandbox is more forgiving but still HTTPS-preferred).
- Webhook delivered, balance still 0 → check site's PHP error log (often a missing pricing config — see SDK 1.3.0 migration notes).

---

## Going live

1. In PayPal Dashboard → toggle from Sandbox to **Live**. The view changes.
2. Click **Apps & Credentials → Create App** *again* — Live mode has its own separate app + credentials.
3. Repeat steps 1-2 above using Live mode keys + a Live mode webhook (different ID than Sandbox).
4. In WP admin → PayPal: switch Mode to *Live*, paste Live keys + Live Webhook ID, *Save*.

**You can save both Sandbox and Live credentials at the same time** in WP admin — the *Mode* toggle picks which is active. Lets you flip back to Sandbox for debugging without re-typing.

---

## What you don't need to do

- ❌ Apply for a "PayPal Commerce Platform" partner account — that's a marketplace product. Standard Business is enough.
- ❌ Pre-create individual buttons — the SDK handles button rendering; you only configure credentials.
- ❌ Add SSL Commerce Pro / Pro / Advanced — Standard checkout works for credit purchases.
- ❌ Embed PayPal SDK JS on your site — checkout is *redirected* to PayPal's hosted page; nothing PayPal-specific runs on your domain.

---

## Common stumbles

| Symptom | Cause | Fix |
|---|---|---|
| "App credentials invalid" | Personal PayPal account (not Business) | Upgrade account type, then create a new app |
| Customer paid but no credits | Webhook ID mismatch | Re-copy Webhook ID from PayPal Dashboard → app → Webhooks |
| Webhook delivers but signature fails | Webhook ID typo or copy-pasted from a different app's webhook | Open the webhook in PayPal → click *Show details* → re-copy ID |
| Sandbox buyer login fails | Wrong sandbox account or password reset needed | PayPal Dashboard → Sandbox → Accounts → reset the buyer's password |
| Going live: existing Sandbox sales gone | Live has separate credentials + ledger | Sandbox is permanently a fake — expected. Live mode starts from $0. |

---

## Currency note

PayPal forces a per-app default currency at app creation. If you want to charge in multiple currencies, create one app per currency, or configure your pricing in the consumer plugin to convert before passing to the SDK. The SDK passes whatever currency is configured at registration; PayPal will reject if the app's default doesn't accept that currency.
