# Headless SDK plan: the SDK renders nothing

Owner rule, 2026-09-30. It applies to this SDK and to every plugin that bundles it.

**The SDK is a skeleton.** It owns the ledger, money maths, gateways, adapters,
REST routes, webhooks, sanitising, stored data and hooks. It does not own any
screen, template, script, notice or user-visible sentence. Each consumer
renders every admin and frontend surface itself, in its own text domain.

Baseline: SDK **1.9.5**. Consumers:

| Consumer | Bundled | Loads the SDK |
|---|---|---|
| WP Career Board Pro | 1.9.5 | yes |
| WB Listora (free; WB Listora Pro uses Free's copy) | 1.7.1 | yes |
| WB Ad Manager Pro | 1.7.0 on main (3.2.0 frozen at 1.9.4) | yes |
| WPConnectPress | 1.7.0 | **no** (the integration was removed in 0c3ae72c; the copy is dead weight) |

## Why

The i18n pass on WP Career Board Pro (2026-09-30) found **522** strings in the
`wbcom-credits-sdk` text domain. Nothing loads that domain and no consumer's POT
file contains it, so every one of them renders in English on a translated site.
Making the SDK load its own translations would still leave the SDK owning pages,
forms and wording that belong to the product. The fix is to take the surfaces
out, not to translate them in place.

## The rule, in checkable terms

After 2.0.0 the SDK:

1. Never `echo`es, `printf`s or includes a template (outside `bin/` and `tests/`).
2. Registers no `template_redirect`, `admin_notices`, `admin_menu`,
   `wp_enqueue_scripts` or storefront (`woocommerce_single_product_summary`,
   `pmpro_setMessage`, ...) output hook.
3. Ships no `templates/`, no `assets/` and no `languages/`.
4. Contains no `__()` / `_e()` / `esc_html__()` family call. Brand names
   (Stripe, PayPal, WooCommerce) are plain literals.
5. Returns **codes and data**: every `WP_Error` has a stable, documented code.
   Its message is short developer English, meant for logs, never for a buyer.

2.0.0 adds a `bin/audit.sh` check for 1-4, so a regression fails CI. It cannot
run before then: the deprecated UI still ships in 1.10.x and would fail it.
Rule 5 is already enforced: `tests/Gateways/ErrorCodesDocTest.php` fails when
`src/` returns a code missing from `docs/ERROR-CODES.md`.

## Inventory: what moves where

| # | Surface (1.9.5) | Strings | Used by consumers today | Replacement in the SDK | Consumer renders |
|---|---|---|---|---|---|
| 1 | `Pack_Admin_Renderer::render()` | 13 | none | keep `sanitize()` | the pack editor form |
| 2 | `Checkout_Settings::render()` | 10 | none (CB Pro from 1.9.5) | keep `get()`, `sanitize()`, `option_name()`, `defaults()` | the checkout settings form |
| 3 | `Coupons::render()` | 19 | none (CB Pro from 1.9.5) | keep `all()`, `sanitize()`, `option_name()` | the coupon editor |
| 4 | `Admin_Form_Renderer` (`render`, `render_field`, `templates/admin/gateways-section.php`, "settings saved" notices) | 14 | **Listora Pro** (`handle_save`, `get_gateway_views`, `render_field`, notices) | keep a data-only save API: `Gateway_Settings::save( $slug, $gateway_id, array $input ): true\|WP_Error` and `Gateway_Settings::views( $slug )` (fields + saved values + webhook URL, no markup) | the gateway cards, fields, nonce and notices |
| 5 | Gateway `get_settings_fields()` labels and option labels (Stripe, PayPal) | 20 | **all three** (Ad Manager and Listora Pro draw them) | fields keep `key`, `type`, `required`, `options` **keys**; the `label` and the option labels are dropped | a label map per field key |
| 6 | Gateway and adapter `get_label()` | 7 | **all three** (buttons and admin lists) | return the untranslated brand name ("Stripe", "WooCommerce") | the name, or its own wording ("Pay with Stripe") |
| 7 | Receipt page: `template_redirect` into `Receipt::maybe_render()` + `templates/frontend/receipt.php` + the "Tax" default + "Receipt not found." | 15 | none (CB Pro from 1.9.5) | keep `Receipt::data()`, `Receipt::format()` and `Receipt::url()`; add `Receipt::can_view( $user_id, $slug, $log_id )`; drop the route | its own receipt route and template |
| 8 | `assets/js/checkout.js` + `wbcomCreditsCfg` + the "Checkout failed." fallback | 1 | none (CB Pro from 1.9.5) | none; the REST routes stay | its own checkout script |
| 9 | `Billing::fields()` labels | 11 | none (CB Pro from 1.9.5) | keep keys, `type`, `required`, `autocomplete`; drop `label` | the billing labels |
| 10 | `Countries::all()` / `label()` (249) and `Currencies::all()` / `name()` (148) | 397 | none (CB Pro from 1.9.5) | codes only, plus `name( $code, $locale )` from PHP intl (`Locale::getDisplayRegion`, ICU currency data), which already exists in every language; English code as a fallback when intl is missing | nothing to translate |
| 11 | REST and webhook `WP_Error` messages (`REST.php`, `Webhook_Controller.php`) | 13 | **Ad Manager** shows the message raw (`assets/js/portal.js:2619`) | stable codes, documented in `docs/ERROR-CODES.md`; messages become untranslated developer English | a message for each code, plus a generic fallback |
| 12 | Storefront "not available" notices (WooCommerce product page, PMPro `pmpro_setMessage`, MemberPress) | 4 | none directly | keep the gate (`purchasable = false`); fire `wbcom_credits_purchase_unavailable( $slug, $context )` instead of printing | the notice, if it wants one |
| 13 | Ledger `note` written by adapters ("Credits from WooCommerce order #%d") | 8 | shown in every consumer's history | write `note = ''`; `reason` and `reference` (already in the schema) carry the source | the history line from `reason` + `reference`; old rows keep their English note |
| 14 | `%d credits` Stripe/PayPal line-item names | 2 | shown on the provider's payment page | the product name comes from the registry (`pack_label` callable) | the pack name |

Rows 1-3 and 7-10 were added in 1.9.x. Only CB Pro bundles those versions, so
they have one consumer to move. Rows 4-6 and 11-13 touch every consumer.

## Release order

A site runs one SDK copy: **the newest one any active plugin bundles**. So an
SDK release that removes a method breaks every older plugin on the same site
that still calls it. Removal therefore comes last.

### 1.10.0: add the headless API (additive, nothing removed)
- `Gateway_Settings::views()` / `save()` (row 4), label-free field schemas
  (rows 5 and 9) returned **alongside** the old labelled ones, `Receipt::can_view()`,
  intl-backed `Countries::name()` / `Currencies::name()`, the
  `wbcom_credits_purchase_unavailable` action (fired **next to** the existing
  notices), and `docs/ERROR-CODES.md`.
- Behaviour a non-migrated consumer can see does **not** change in 1.10.0:
  adapters still write their English `note` alongside `reason` + `reference`,
  error messages keep their text, and notices still print. The newest copy
  serves every plugin on a site, so any such change waits for 2.0.0.
- Every UI method gets a `@deprecated 1.10.0` docblock that points to its
  replacement. No runtime `_deprecated_function()` yet: it would print
  notices on sites whose other plugins have not migrated.
- CONSUMER-RULES gets rule 8: "The SDK renders nothing."
- Tagged and QA'd once, per RELEASE-POLICY.

### Consumers migrate (each in its own next release, bundling 1.10.x)
Checklists below. Each migration is its own card in that product's board.

### 2.0.0: remove (only when every row in the table below says "released")
- Delete rows 1-14's surfaces, `templates/`, `assets/`, the text domain and
  the labelled field schemas. Adapters stop writing `note`; error messages
  become untranslated developer English; storefront notices stop printing.
- Keep the old class names as thin stubs for one major. Render methods do
  nothing and call `_doing_it_wrong()`, so a site still running an old
  consumer loses a form instead of white-screening.
- Stubs removed in 3.0.0.

### Migration status

| Consumer | 1.10 bundled | Migrated | Released |
|---|---|---|---|
| WP Career Board Pro | | | |
| WB Listora | | | |
| WB Listora Pro | (uses Free's copy) | | |
| WB Ad Manager Pro | | | |
| WPConnectPress | n/a | n/a: remove the unused bundle | |

## Consumer checklists

### WP Career Board Pro (largest: it adopted the 1.9.x UI)
- **Admin Credits tab:** own the pack, checkout-settings, coupon and gateway
  forms (rows 1-5). Keep the SDK's `sanitize()` / `save()` calls.
- **Frontend credit-balance block:** own the billing labels, the country list
  (via `Countries::name()`), the checkout script and the error messages
  (rows 8-11).
- **Receipt:** own the receipt route and template (row 7). The credit receipt
  email already reads `Receipt::data()`.
- **Transactions list:** render history lines from `reason` + `reference` (row 13).
- **Every string** in the `wp-career-board-pro` domain. Its POT must list them all.

### WB Listora Pro
- **Gateway settings:** replace `Admin_Form_Renderer::handle_save()`,
  `get_gateway_views()` and `render_field()` (class-pro-plugin.php:1210-1213,
  :2661-2751) with `Gateway_Settings::views()` / `save()`. Render the fields
  and the saved/failed notice in `wb-listora-pro`. Keep the nonce, field
  names and option key it uses today, so saved keys survive.
- **Labels:** map gateway and adapter labels (rows 5-6) in Pro's domain.

### WB Listora (free)
- **Labels:** map the gateway labels on the credits tab
  (class-template-helpers.php:404-410).
- **Checkout errors:** already shows its own copy on checkout errors
  (view.js:612); map the documented codes for specific messages.
- **Ledger history:** render from `reason` + `reference`.

### WB Ad Manager Pro
- **Checkout errors:** stop showing `responseJSON.message` raw
  (assets/js/portal.js:2619-2622). Map `responseJSON.code` to Pro's own strings.
- **Gateway settings:** label map for the gateway settings fields
  (class-credits-settings.php:804-961). It already draws its own form and
  writes `wbcom_credits_gateway_settings_{slug}` directly. That option key is
  now a documented, frozen contract.
- **Adapter labels:** label map (:434, :486, :565).

### WPConnectPress
- **Remove the bundle:** delete `libs/wbcom-credits-sdk` and its stale tooling
  references (phpstan.neon.dist:29, bin/architecture-checks.sh:332, plan/INVARIANTS.yaml).
  It is not loaded, but it still ships and still enters the "newest copy
  wins" election on every site where the plugin is active.

## Contracts that do not change

These stay exactly as they are through 2.0.0. Consumers call them today.

- **`Credits::`** balance, hold, deduct, refund, topup and `*_money` methods,
  `get_ledger`, `purchase_paths`, `get_purchase_url`, `is_money` and
  `resolve_money_currency`.
- **`Money::` and `Ledger::`** public methods.
- **Registry:** `register()` keys (`money`, `return_url`, pricing).
- **Gateway lookups:** `Gateway_Registry::for_slug()->get / get_all / get_available`.
- **REST routes:** `/checkout/{gw}`, `/claim/{gw}`, `/balance` and `/webhook/`.
- **Stored data:** the option `wbcom_credits_gateway_settings_{slug}`.
- **Action signatures:** `wbcom_credits_topped_up`, `_deducted`, `_refunded` and `_low`.

## Verification (per consumer, before its release)

1. `wp i18n make-pot` on the consumer: every string it shows is in its own POT.
2. `grep -r "wbcom-credits-sdk'"` outside `libs/`: zero hits.
3. With a pseudo-locale `gettext` filter that prefixes every translated string,
   walk the credits admin, checkout, receipt and history. Any visible string
   without the prefix is a miss.
4. Run the site with **only the 2.0.0 SDK** as the winning copy (the
   consumer's own bundle, or the newest other bundle). Every consumer surface
   still renders, and nothing calls a removed method.
