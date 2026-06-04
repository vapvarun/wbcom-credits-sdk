# Changelog

All notable changes to the Wbcom Credits SDK are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the SDK follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed (money-path)

- **[CRITICAL] Atomic webhook idempotency.** `Gateways\Idempotency` used an option-backed FIFO ring with a read-modify-write (`get_option` → `in_array` → `update_option`). Two concurrent deliveries of the same provider event could both pass `is_processed()` and both credit the user. Idempotency now uses a dedicated `{prefix}_credit_processed_events` table with a `UNIQUE (slug, gateway, event_id)` key (new `Gateways\Processed_Events` class). `mark_processed()` performs a single `INSERT IGNORE` and returns `true` only when a row was newly inserted (`rows_affected === 1`) — so exactly one of N racing deliveries wins the claim and the rest are rejected. `Abstract_Gateway::handle_webhook()` now **claims the event atomically BEFORE any ledger write** (claim-then-act); the post-credit `mark_processed()` calls were removed. `is_processed()` is retained as a cheap pre-check only. `Idempotency`'s public API (`is_processed`, `mark_processed`, `reset_for_tests`) is unchanged — it is now a thin facade over `Processed_Events`.
- **[HIGH] Gateway refunds now fire the generic `wbcom_credits_refunded` action.** A gateway-initiated refund revokes credits via `Credits::adjust(-credits)` (which intentionally fires no SDK action) and logged a `Transaction_Log` refund row, but only fired the gateway-scoped `wbcom_credits_gateway_refund` action — so consumer refund consumers (audit log, outgoing webhooks, notifications) bridged to the documented `wbcom_credits_refunded` contract were silently skipped for every Stripe/PayPal refund. `Abstract_Gateway::process_refund()` now fires `wbcom_credits_refunded( $slug, $user_id, $item_id )` (item_id `0` for gateway refunds; matches the documented 3-arg contract) after a successful revoke, only when credits were actually revoked. The richer `wbcom_credits_gateway_refund` action still fires alongside it.
- **[HIGH] Stripe refund → parent checkout linkage.** `Stripe::normalize_event()` for `charge.refunded` set `session_id = $charge['payment_intent']` (a `pi_…` id) first. But the parent payment row is keyed by the Checkout Session id (`cs_…`), so on the normal path (payment_intent present) the parent lookup never matched and credits were never revoked. Fixes: (1) checkout creation now stamps the session id onto the PaymentIntent via `payment_intent_data[metadata][wbcom_session]`, which Stripe copies onto the charge, so `charge.refunded` carries the `cs_…` id back; the normalizer now **prefers** that metadata. (2) For legacy sessions created before the stamp, the normalizer falls back to a secondary lookup that translates `payment_intent` → recorded `cs_…` via the new `Transaction_Log::find_checkout_by_payment_intent()`. The checkout `Transaction_Log` row now stores `payment_intent` (new column + `idx_intent` key). The amount/partial-refund clamp logic is unchanged.

### Schema

- DB schema version bumped to **2**, tracked per-consumer in the `wbcom_credits_db_version_{prefix}` option (introduced by this release; previously the SDK had no DB-version option and relied on `SHOW TABLES` probes alone). `Registry::boot_all()` now calls a guarded `maybe_upgrade_schema()` that runs the idempotent create/upgrade pass only when the stored version is behind. Changes in v2: new `{prefix}_credit_processed_events` table (`UNIQUE (slug, gateway, event_id)`); new `payment_intent` column + `idx_intent` key on `{prefix}_credit_gateway_log` (added in place via `dbDelta`). No back-fill/migration of the old option-ring is performed — the ring was a short-lived dedupe cache (last 1000 event ids), and any event old enough to only exist in the ring is far past every provider's webhook-retry window, so there is nothing to migrate. The table is the single source of truth going forward.

### Tests

- `tests/Gateways/IdempotencyTest.php` rewritten for the atomic model, incl. a concurrency test asserting exactly one of N claims for the same event wins.
- `tests/Gateways/GatewayRefundEventTest.php` (new) — gateway refund fires `wbcom_credits_refunded` once with the documented payload, prorated partial refunds, and replay safety (no double-revoke / no re-fire).
- `tests/Gateways/StripeRefundLinkageTest.php` (new) — `charge.refunded` with `payment_intent` present resolves the parent via the metadata stamp (normal path) and via the `payment_intent` secondary lookup (legacy path); checkout normalizer captures `payment_intent`.
- `tests/Support/FakeWpdb.php` extended with `query()` (INSERT IGNORE + UNIQUE enforcement, refund UPDATE), `get_row()`, `rows_affected`, and parsed column DEFAULTs so the shim mirrors the real constraint/atomic behaviour.

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
