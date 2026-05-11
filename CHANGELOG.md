# Changelog

All notable changes to the Wbcom Credits SDK are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the SDK follows [Semantic Versioning](https://semver.org/).

## [Unreleased — 1.3.0]

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
