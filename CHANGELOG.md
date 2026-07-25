# Changelog

All notable changes to the Wbcom Credits SDK are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the SDK follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **First-class "money mode" so money-denominated consumers can't mix major/minor units (#3).** `Money` (1.5.0) gave consumers a correct converter, but using it was opt-in at every entry point — admin add, the consumer's webhook, the payment adapters — and missing any one silently mixes minor and major units, corrupting a balance (found in a live consumer: `(int) 0.5` stored 0 credits on a "success"). A consumer now declares its ledger is money once — `'money' => array( 'currency' => 'USD' )` (an ISO code or a callable) — and uses the new convenience API that converts MAJOR-unit amounts to the ledger's integer MINOR units through `Money` at a single enforced boundary: `Credits::topup_money()`, `hold_money()`, `deduct_money()`, `refund_money()`, `adjust_money()`, and `balance_money()` (reads back as a major-unit float), plus `Credits::is_money()`. Currency resolves from the call argument, else the consumer's `money.currency`, else USD. Token consumers that register no `money` key are unaffected — the integer `topup()/deduct()/refund()` behave exactly as before. Ledger stays `amount INT`. Additive; no schema change.

### Tests

- `tests/Credits/CreditsMoneyModeTest.php` (new) — locks: sub-unit top-ups are not lost (0.5 → 50 minor, the truncation bug), USD 147.35 → 14735 round-trips, zero-decimal (JPY, no ×100) and three-decimal (KWD, via a callable currency) conversion, the hold→deduct→refund money lifecycle, signed `adjust_money`, and `is_money()` gating.

## [1.4.2] - 2026-07-13

### Added

- **`Transaction_Log::list_transactions()` + `count_transactions()` — the read side of the gateway log.** The append-only gateway log had writers (checkout/refund inserts) and single-row lookups but no way for a consumer to LIST it. These add a paginated, newest-first reader (filterable by `kind`, `gateway`, `user_id`; `limit` clamped 1..100 + `offset`) and a matching count for pagination totals, so a consuming plugin can surface an admin "Transactions" view — every purchase and refund with its money amount, credits, gateway, and the `session_id` the refund route needs. Read-only; no schema change.

### Tests

- `tests/Gateways/TransactionLogReaderTest.php` (new) — locks newest-first ordering, limit/offset pagination, `kind`/`gateway`/`user_id` filtering, and cross-slug isolation (one consumer never sees another's rows through the shared table).

## [1.4.1] - 2026-07-13

### Fixed

- **[HIGH] PMPro + MemberPress adapter idempotency is now atomic (parity with WooCommerce).** `PMProAdapter::on_level_change()` and `on_subscription_payment()` deduped with a read-then-write user-meta flag (`get_user_meta()` → `topup()` → `update_user_meta()`); `MemberPressAdapter::on_transaction_completed()` deduped with a `note LIKE '%...%'` scan of the SDK ledger table. Both are read-modify-write guards with a TOCTOU window: two concurrent deliveries of the same event (a retried PMPro level-change hook, or a replayed MemberPress transaction event) could both read "not processed" before either saved, and both top up. All three adapter methods now route dedupe through the same atomic `Gateways\Processed_Events::claim()` (UNIQUE `INSERT IGNORE`) that the WooCommerce adapters and the gateway webhook path already use — claiming FIRST, before the credits lookup, under a stable per-event id (`pmpro:level:{user}:{level}:{date}`, `pmpro:order:{order_id}`, `mepr:txn:{txn_id}`) tagged `adapter:{id}`. PMPro's legacy meta flag is retained as a human-readable support/reconciliation marker but is no longer the guard. MemberPress's non-atomic `is_already_processed()` ledger scan is removed (dead code once the atomic claim replaces it). The processed-events table is already created at boot for every consumer, so no schema change is needed.

### Added

- **Gateway parity matrix test (`tests/Gateways/GatewayParityMatrixTest.php`).** Drives the real `Stripe` and `PayPal` gateway classes through equivalent checkout-completed and refund events via the shared `Abstract_Gateway::handle_webhook()` orchestration, and asserts the domain outcome (credits topped up / revoked, `Transaction_Log` row shape, `wbcom_credits_refunded` payload) is identical for both providers. Locks the guarantee that `normalize_event()` is the only place Stripe and PayPal are allowed to diverge — no divergence was found in the shared path.

### Tests

- `tests/Adapters/AdapterIdempotencyTest.php` extended with concurrent-delivery cases for PMPro `on_level_change`, PMPro `on_subscription_payment`, and MemberPress `on_transaction_completed` — for each, N racing deliveries of the same event credit the user exactly once.
- `tests/Gateways/GatewayParityMatrixTest.php` (new) — see Added above.

## [1.4.0] - 2026-07-13

### Added (frontend checkout)

- **Reusable JS checkout helper (`assets/js/checkout.js`).** Registers a `window.wbcomCreditsCheckout({ slug, gateway, pack_id, credits, returnUrl })` global that POSTs to `/{slug}/checkout/{gateway}` (with `X-WP-Nonce`), then redirects the browser to the hosted checkout URL the SDK returns. `Registry` registers (not enqueues) a `wbcom-credits-checkout` script handle localized with `wbcomCreditsCfg = { restRoot, nonce }`, once per request regardless of consumer count; consuming plugins call `wp_enqueue_script('wbcom-credits-checkout')` where they render a buy button. This is the browser half of the existing `/checkout/{gateway}` REST route — no consumer has to hand-roll the fetch/redirect.
- **Reusable admin pack-editor (`Gateways\Pack_Admin_Renderer`).** `render( $option_name )` echoes an escaped, dependency-free fieldset for credit packs ({credits, price}) plus a custom-amount group (enabled / per-credit rate / min / max) and currency; `sanitize()` (hand it to `register_setting()`) normalizes the POST into the exact `pricing`-shaped array `Pricing::resolve()` consumes, dropping rows with non-positive credits or price. Consuming plugins get the packs + custom-amount admin UI without rebuilding it.
- See `docs/CONSUMER_FRONTEND_CHECKOUT.md` for the end-to-end wiring recipe.

### Fixed (money-path)

- **[CRITICAL] Atomic webhook idempotency.** `Gateways\Idempotency` used an option-backed FIFO ring with a read-modify-write (`get_option` → `in_array` → `update_option`). Two concurrent deliveries of the same provider event could both pass `is_processed()` and both credit the user. Idempotency now uses a dedicated `{prefix}_credit_processed_events` table with a `UNIQUE (slug, gateway, event_id)` key (new `Gateways\Processed_Events` class). `mark_processed()` performs a single `INSERT IGNORE` and returns `true` only when a row was newly inserted (`rows_affected === 1`) — so exactly one of N racing deliveries wins the claim and the rest are rejected. `Abstract_Gateway::handle_webhook()` now **claims the event atomically BEFORE any ledger write** (claim-then-act); the post-credit `mark_processed()` calls were removed. `is_processed()` is retained as a cheap pre-check only. `Idempotency`'s public API (`is_processed`, `mark_processed`, `reset_for_tests`) is unchanged — it is now a thin facade over `Processed_Events`.
- **[HIGH] Adapter idempotency is now atomic (TOCTOU fix).** The bundled membership/subscription adapters deduped with a read-then-write order/membership meta flag (`get_meta('_wbcom_credits_processed')` → `topup()` → `save()`). WooCommerce fires BOTH `order_status_completed` and `_processing`, and any status transition can arrive from concurrent requests (gateway IPN + admin, or two IPNs) — so two deliveries could both read "not processed" before either saved and both top up. `WooCommerce`, `WooSubscriptions`, and `WooMemberships` adapters now route dedupe through the same atomic `Gateways\Processed_Events::claim()` (UNIQUE `INSERT IGNORE`) the webhook path uses, with a stable per-order/per-membership event id under an `adapter:{id}` gateway tag (`woo:order:{id}`, `woosub:order:{id}`, `woomembership:membership:{id}`). The adapter claims FIRST and only credits when it won the claim; the legacy meta flag is retained as a human-readable support marker but is no longer the guard. WooMemberships keeps its original "credit once, ever, per membership" semantics (the membership-id claim row persists across an active→expired→active cycle). The processed-events table is already created at boot for every consumer, so no new schema is needed.
- **[HIGH] Gateway refunds now fire the generic `wbcom_credits_refunded` action — with the revoked amount + linkage context.** A gateway-initiated refund revokes credits via `Credits::adjust(-credits)` (which intentionally fires no SDK action) and logged a `Transaction_Log` refund row, but only fired the gateway-scoped `wbcom_credits_gateway_refund` action — so consumer refund consumers (audit log, outgoing webhooks, notifications) bridged to the documented `wbcom_credits_refunded` contract were silently skipped for every Stripe/PayPal refund. `Abstract_Gateway::process_refund()` now fires `wbcom_credits_refunded( $slug, $user_id, $credits_revoked, $context )` after a successful revoke, only when credits were actually revoked. The richer `wbcom_credits_gateway_refund` action still fires alongside it. **Signature change (additive, but the 3rd arg changed meaning):** the generic `wbcom_credits_refunded` action is now `( $slug, $user_id, $amount, $context )` across BOTH fire sites — the gateway path AND `Credits::refund()` (hold-lifecycle). The 3rd arg is the refunded/revoked **credit amount** (positive int); the 4th `$context` is an assoc array carrying `reason` (`gateway_refund`|`hold_refund`), `item_id`, `ledger_id`, and — for gateway refunds — `gateway`, `session_id`, `provider_ref`. The previous 3rd arg on the hold path was `item_id`, which now lives in `$context['item_id']`. Existing 3-arg listeners keep firing (they receive slug/user_id unchanged); consumers reading the 3rd arg as item_id must move to `$context['item_id']`. This unblocks Pro consumers (audit-log amount attribution, perk reversal) that previously had no way to read the amount or map a gateway refund back to a listing. The public method API surface is unchanged (this is a hook-argument change, not a method-signature change), so `bin/.api-surface.txt` is unaffected.
- **[HIGH] PayPal refund → parent checkout linkage (PayPal analogue of the Stripe fix).** `PayPal::normalize_event()` for `PAYMENT.CAPTURE.REFUNDED` resolved the parent checkout only from `supplementary_data.related_ids.order_id` / `parent_payment`, which PayPal does not reliably include on refund webhooks — so refunds that omitted it never matched the recorded parent (keyed by the PayPal order id) and credits were never revoked; there was no fallback (Stripe already had one). Fixes, mirroring Stripe's prefer-stamp-then-fallback: (1) checkout creation PATCHes the freshly-minted order's purchase-unit `custom_id` to embed the order id (`{ slug, user_id, credits, session }`), which PayPal copies onto the capture and the refund resource — so the refund webhook carries our order id back; the normalizer now **prefers** that stamped `custom_id`. (2) The checkout-completed event now records the PayPal **capture id** as `provider_ref` (stored in `Transaction_Log.payment_intent`), so a refund carrying neither the stamp nor the order id falls back to `Transaction_Log::find_checkout_by_payment_intent()` keyed on the capture id (`related_ids.captured_payment` / `up_id`). The `custom_id` PATCH is best-effort (non-fatal on failure) because the capture-id fallback covers it. Zero/negative refund amounts are now treated as non-events (matches Stripe); the captured-total clamp is unchanged and still enforced in `Abstract_Gateway::process_refund()`.
- **[HIGH] `payment_intent` column now actually lands on EXISTING installs (the linkage fix below depended on it).** `Transaction_Log::maybe_create_table()` early-returned the instant its `SHOW TABLES LIKE` guard found the table present, so the `CREATE TABLE` — the only place the `payment_intent` column + `idx_intent` key were declared — ran on FRESH installs only. On every upgraded site the column was missing and the refund parent-linkage below silently failed exactly where it was meant to be fixed. `maybe_create_table()` now runs `CREATE TABLE` only when the table is absent, then ALWAYS calls a new idempotent private helper `ensure_intent_column()` that probes `SHOW COLUMNS` / `SHOW INDEX` and adds the column/index via explicit `ALTER TABLE` when missing (`VARCHAR(191) NOT NULL DEFAULT ''` to match the fresh schema; index `(slug, gateway, payment_intent)`). It is re-runnable (no-op once present) and gated by the per-consumer `wbcom_credits_db_version_{prefix}` option so a v1→v2 upgrade hits the ALTER exactly once. Explicit `ALTER` is used rather than relying on `dbDelta`: `dbDelta` never ran on the early-return existing-table path and is unreliable at adding non-PRIMARY/UNIQUE keys — for money code the guarded, verifiable path is clearer. Public API surface unchanged.
- **[HIGH] Stripe refund → parent checkout linkage.** `Stripe::normalize_event()` for `charge.refunded` set `session_id = $charge['payment_intent']` (a `pi_…` id) first. But the parent payment row is keyed by the Checkout Session id (`cs_…`), so on the normal path (payment_intent present) the parent lookup never matched and credits were never revoked. Fixes: (1) checkout creation now stamps the session id onto the PaymentIntent via `payment_intent_data[metadata][wbcom_session]`, which Stripe copies onto the charge, so `charge.refunded` carries the `cs_…` id back; the normalizer now **prefers** that metadata. (2) For legacy sessions created before the stamp, the normalizer falls back to a secondary lookup that translates `payment_intent` → recorded `cs_…` via the new `Transaction_Log::find_checkout_by_payment_intent()`. The checkout `Transaction_Log` row now stores `payment_intent` (new column + `idx_intent` key). The amount/partial-refund clamp logic is unchanged.

### Schema

- DB schema version is now **3**, tracked per-consumer in the `wbcom_credits_db_version_{prefix}` option (introduced by this release; previously the SDK had no DB-version option and relied on `SHOW TABLES` probes alone). `Registry::boot_all()` calls a guarded `maybe_upgrade_schema()` that runs the idempotent create/upgrade pass only when the stored version is behind. Changes by version: **v2** added the `{prefix}_credit_processed_events` table (`UNIQUE (slug, gateway, event_id)`) and *intended* to add the `payment_intent` column + `idx_intent` key on `{prefix}_credit_gateway_log` — but the early-return bug meant that column was added on FRESH installs only, leaving sites that booted the v2 build stuck at version 2 WITHOUT the column (the gate thought the work was done). **v3** re-runs the now-fixed idempotent backfill (`Transaction_Log::ensure_intent_column()` — explicit guarded `ALTER TABLE`, NOT `dbDelta`, which is skipped on the existing-table path and unreliable for plain keys) so those corrupted v2 installs get the `payment_intent` column + `idx_intent` index added; it no-ops where they already exist, so the bump is safe for fresh installs too. No back-fill/migration of the old option-ring is performed — the ring was a short-lived dedupe cache (last 1000 event ids), and any event old enough to only exist in the ring is far past every provider's webhook-retry window, so there is nothing to migrate. The table is the single source of truth going forward.

### Tests

- `tests/Gateways/IdempotencyTest.php` rewritten for the atomic model, incl. a concurrency test asserting exactly one of N claims for the same event wins.
- `tests/Gateways/GatewayRefundEventTest.php` (new) — gateway refund fires `wbcom_credits_refunded` once with the documented payload (now asserting the 4-arg `( $slug, $user_id, $amount, $context )` shape: 3rd arg is the revoked amount, 4th carries gateway/session_id/provider_ref/ledger_id/reason), prorated partial refunds, and replay safety (no double-revoke / no re-fire).
- `tests/Adapters/AdapterIdempotencyTest.php` (new) — N concurrent deliveries of the same WooCommerce order (and the processing→completed pair) credit exactly once via the atomic `Processed_Events::claim()`; a different order still credits.
- `tests/Gateways/PayPalRefundLinkageTest.php` (new) — `PAYMENT.CAPTURE.REFUNDED` resolves the parent via the stamped `custom_id` (preferred), via `supplementary_data.related_ids.order_id`, and — when both are absent — via the `Transaction_Log` capture-id fallback; an unresolvable refund returns a null event; checkout normalizer captures the capture id as `provider_ref`.
- `tests/Credits/CreditsRefundEventTest.php` (new) — `Credits::refund()` fires the generic action with the new 4-arg signature (amount as 3rd arg, `item_id`/`ledger_id`/`note`/`reason` in `$context`).
- `tests/Gateways/StripeRefundLinkageTest.php` (new) — `charge.refunded` with `payment_intent` present resolves the parent via the metadata stamp (normal path) and via the `payment_intent` secondary lookup (legacy path); checkout normalizer captures `payment_intent`.
- `tests/Gateways/TransactionLogUpgradeTest.php` (new) — locks the v1→v2 upgrade PATH: a pre-existing SDK-1.2.0 gateway-log table (no `payment_intent`, no `idx_intent`) gains both after `maybe_create_table()`, the `payment_intent` refund lookup resolves the parent afterwards, the upgrade is idempotent on re-run (no double-add, no error), and a fresh install still ships the column from its `CREATE TABLE`.
- `tests/Support/FakeWpdb.php` extended with `query()` (INSERT IGNORE + UNIQUE enforcement, refund UPDATE), `get_row()`, `rows_affected`, parsed column DEFAULTs, and `SHOW COLUMNS` / `SHOW INDEX` / `ALTER TABLE ADD COLUMN|KEY` plus parsed per-table column/index registries so the shim mirrors the real constraint/atomic/DDL behaviour (and the upgrade-path test exercises both the present and missing branches).

### Documentation

The SDK's primary purpose — captured at the top of the README — is two-fold:

1. **The SDK owns every top-up path.** Consumer plugins (WB Listora, ProjectFlow, Career Board, Ad Manager, …) do NOT build their own payment flow. They register with the SDK and the SDK provides all top-up surfaces: bundled membership/subscription adapters (WooCommerce, WooSubscriptions, WooMemberships, PMPro, MemberPress) AND direct payment gateways (Stripe, PayPal, plus custom gateways via the gateway interface). When a vendor's WooSubscription renews, when a customer buys a credit pack via Stripe checkout, when an admin clicks "Grant credits" — all of those land in the same SDK ledger via the same primitives.

2. **The SDK is the canonical event source.** Because consumer plugins don't own the top-up surfaces, they CANNOT learn about top-ups from inside their own code. They MUST listen to the SDK's `wbcom_credits_*` actions. This is what the new **Consumer Architecture Patterns** section in the README is for: every consumer plugin needs an event bridge that re-fires the SDK's generic actions as plugin-namespaced actions, slug-guarded. Without the bridge, downstream logic (auto-resume, audit, notifications, outgoing webhooks) silently breaks for the majority of paying customers — because the majority of paying customers use one of the SDK adapter paths the consumer's wrapper never sees.

New README sections (between *Manual Credit Operations* and *Transaction History*):

- **Consumer Architecture Patterns — Pattern 1: The SDK Event Bridge.** Concrete anti-pattern (firing from your own wrapper — broken), pattern (bridge SDK actions to your namespace — correct), reference implementation, slug-guard, and an explicit note that `Credits::adjust()` does not fire SDK actions.
- **Consumer Architecture Patterns — Pattern 2: Hold → Commit Atomicity.** When a credit deduction is paired with downstream side effects (post meta, perk activation, external API call), use `Credits::hold()` → side effects → `Credits::deduct()` (commit) with `Credits::cancel_hold()` on failure. Prevents the "credits deducted but perk failed" class of bug. Ledger always shows `hold + deduct` or `hold + cancel_hold` — never an orphan debit.
- **Hooks section** clarified with the actions table (event → args → trigger conditions) plus an explicit note that adapter-originated top-ups fire the same events as direct wrapper calls.

### Why this matters

Consumer plugin WB Listora shipped its auto-resume-on-topup feature with a listener bound to its own plugin-namespaced action — fired only from its own wrapper. Real-world vendors top up via WooSubscriptions, MemberPress, PMPro, etc., which call `Credits::topup()` directly through the SDK adapter chain. The plugin's listener never ran for any of those customers — auto-resume was silently broken for the majority of paying users. Documenting the bridge pattern + the SDK-owns-top-ups principle in the SDK README ensures the next consumer plugin doesn't re-discover this in production.

## [1.3.0] - 2026-05-11

### Security (BREAKING for direct-gateway consumers)
- **[HIGH] Server-authoritative pricing (issue [#2](https://github.com/vapvarun/wbcom-credits-sdk/issues/2)).** The `/checkout/{gateway}` REST endpoint no longer accepts client-supplied `price_cents`. Pre-1.3.0, any logged-in user could POST `credits=10000` + `price_cents=1` and walk away with 10,000 credits for 1¢. The new `Wbcom\Credits\Gateways\Pricing::resolve()` resolver requires consumer plugins to register a `pricing` config at `Registry::register()` time (either a `packs` map or a `credits_to_price_cents` callback with `min_credits`/`max_credits` bounds). The SDK computes `price_cents` server-side from `pack_id` or `credits`. Any `price_cents` in the request body is silently dropped.
- Direct-gateway consumers must update their `Registry::register()` calls to add a `pricing` key before bundling SDK 1.3.0 — without it, the checkout endpoint returns `503 pricing_not_configured`. Migration playbook: `docs/MIGRATION-1.3.0-pricing.md`.
- WooCommerce / WC Subscriptions / WC Memberships / PMPro / MemberPress adapter paths are unaffected — those flows were already server-authoritative (price is read from the WC product or membership-plan price; client cannot tamper).

### Added
- `src/Gateways/Pricing.php` — server-authoritative pricing resolver. Supports pack mode + callback mode. Throws `PricingException` with typed error codes + HTTP status mapping.
- `tests/Gateways/PricingTest.php` — 12 security regression tests covering pack/callback success, client-supplied price ignored, missing config 503, unknown pack 404, bounds enforcement, invalid callback result 500.
- `tests/Versions/IdempotentRegisterTest` — locks the multi-version coexistence contract (registering the same version twice does not overwrite the first callback).
- `tests/Versions/LatestWinsTest` — locks the highest-semver-wins rule for `Versions::initialize_latest_version()`.
- `tests/Ledger/SchemaContractTest` — locks the canonical Ledger columns (`user_id`, `item_id`) at the SDK level. Schema renaming surfaces as a CI failure before merge.
- `docs/SETUP-STRIPE.md` — 3-step site-owner setup guide for Stripe (API keys + webhook + test card). Tested with free Stripe accounts; no special tier required.
- `docs/SETUP-PAYPAL.md` — 3-step site-owner setup guide for PayPal (Business account + app credentials + webhook). Notes that Personal accounts cannot accept API payments.
- `docs/MIGRATION-1.3.0-pricing.md` — consumer-plugin playbook for adopting the new pricing config. Covers pack mode, callback mode, error codes, and the wave-rollout recommendation.
- `docs/MIGRATION-1.3.0-career-board.md` — playbook for wp-career-board-pro to migrate its custom `employer_id`/`post_id` schema to the SDK's canonical columns.
- `PORTFOLIO-PLAN.md` — long-term 4-phase strategy for the SDK as a shared dependency across 5+ Wbcom plugins.

### Changed
- `Webhook_Controller::create_checkout()` now resolves `{credits, price_cents, currency}` via `Pricing::resolve()` before passing to the gateway. The arg shape on the REST route is `{gateway, pack_id?, credits?, return_url?}` — `price_cents` removed.
- `Registry::register()` accepts an optional `pricing` config key. Backwards-compatible with consumers that don't set it (those consumers get a 503 when the checkout endpoint is called — by design).

### Clarified (non-breaking, documentation-only at SDK level)
- **Schema contract.** The SDK ships one canonical Ledger schema with columns `user_id` and `item_id`. Consumer plugins MUST NOT pre-empt `Ledger::maybe_create_table()` by shipping their own `CREATE TABLE` with renamed columns. Domain-readable names (employer, attendee, member) belong in the consumer plugin's public-facing API, not in the database schema. See `MIGRATION-1.3.0-career-board.md` for an example migration.

### Required action for consumer plugins
- **All consumer plugins using direct-pay gateways** must add a `pricing` config to their `Registry::register()` call. See `MIGRATION-1.3.0-pricing.md`.
- **wp-career-board-pro 1.1.0** was in violation of the schema contract — fixed in [wp-career-board-pro 1.1.1](https://github.com/vapvarun/wp-career-board-pro/releases/tag/v1.1.1).
- **WB Ad Manager Pro 1.6.0** uses the direct-gateway checkout per issue #2; must add pricing config before bundling 1.3.0.

## [1.2.0] - 2026-04-XX

### Added
- Direct payment gateways: Stripe and PayPal (`src/Gateways/`).
- `Admin_Form_Renderer` for consumer-side gateway settings UI (`src/Gateways/Admin_Form_Renderer.php`).
- Per-checkout `return_url` override on `Credits::create_checkout()`.
- `Credits::get_gateway_views()` + `render_field()` helpers for consumer-card markup.
- Webhook signature verification, idempotency tracking, pending-checkout reconciliation.

### Existing test coverage
- `tests/Gateways/IdempotencyTest`
- `tests/Gateways/PendingCheckoutsTest`
- `tests/Gateways/GatewayEventTest`
- `tests/Gateways/SignatureVerifierTest`

## [1.1.1] - 2026-XX-XX

### Fixed
- Self-healing class loader: each bundled SDK copy now fills in only the classes the earlier-loaded copy missed. Resolves "Class not found" fatals when an older bundle won the load race.

## [1.1.0] - 2026-XX-XX

### Added
- Template loader + `templates/` scaffold.
- Adapter contract: WooCommerce, WooSubscriptions, WooMemberships, PMPro, MemberPress.
- REST endpoints: `/balance`, `/history`, `/topup` under `/wbcom-credits/v1/{slug}/`.

## [1.0.0] - 2026-XX-XX

Initial release. Append-only ledger, hold/deduct/refund lifecycle, multi-consumer Registry, per-plugin REST namespace.
