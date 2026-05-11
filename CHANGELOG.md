# Changelog

All notable changes to the Wbcom Credits SDK are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the SDK follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `tests/Versions/IdempotentRegisterTest` — locks the multi-version coexistence contract (registering the same version twice does not overwrite the first callback).
- `tests/Versions/LatestWinsTest` — locks the highest-semver-wins rule for `Versions::initialize_latest_version()`.
- `tests/Ledger/SchemaContractTest` — locks the canonical Ledger columns (`user_id`, `item_id`) at the SDK level. Schema renaming surfaces as a CI failure before merge.
- `docs/MIGRATION-1.3.0-career-board.md` — playbook for wp-career-board-pro to migrate its custom `employer_id`/`post_id` schema to the SDK's canonical columns.
- `PORTFOLIO-PLAN.md` — long-term 4-phase strategy for the SDK as a shared dependency across 5+ Wbcom plugins.

### Clarified (non-breaking, documentation-only at SDK level)
- **Schema contract.** The SDK ships one canonical Ledger schema with columns `user_id` and `item_id`. Consumer plugins MUST NOT pre-empt `Ledger::maybe_create_table()` by shipping their own `CREATE TABLE` with renamed columns. Domain-readable names (employer, attendee, member) belong in the consumer plugin's public-facing API, not in the database schema. See `MIGRATION-1.3.0-career-board.md` for an example migration.

### Required action for consumer plugins
- **wp-career-board-pro 1.1.0** is currently in violation of the schema contract — its `core/class-pro-install.php` creates `wp_wcb_credit_ledger` with `employer_id`/`post_id` columns, which causes all SDK queries to fail silently with `Unknown column 'user_id'`. Cannot adopt SDK 1.3.0 until the migration documented in `MIGRATION-1.3.0-career-board.md` completes.

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
