# Wbcom Credits SDK — Portfolio Plan

This document captures the long-term strategy for the Wbcom Credits SDK as a shared dependency across 5+ Wbcom WordPress plugins. The plan reconciles the canonical repo with shipping vendored copies, locks the multi-plugin coexistence guarantees as CI, and lays the path to cross-plugin features (transfer API, unified wallet, shared admin dashboard).

Source-of-truth document. Update as phases complete.

---

## North star

One **wbcom-credits-sdk** repo is the source of truth for every Wbcom plugin that touches credits. Adding the 6th plugin should take 1 hour and ship with the same quality guarantees as plugin #1. Cross-plugin features (transfer, unified wallet, dashboards) ship from the SDK, never re-implemented per plugin.

---

## Current state — 2026-05-11 audit (corrected after `git pull`)

| Concern | Reality |
|---|---|
| Canonical repo head | `dcfd0bc feat(sdk): expose get_gateway_views() + render_field()` — **post-v1.2.0** |
| Tagged releases | `v1.1.0`, `v1.1.1`, `v1.2.0` |
| Shipping version in vendors | **1.2.0** in `WPConnectPress/vendor/wbcom-credits-sdk/` and `wp-career-board-pro/vendor/wbcom-credits-sdk/` |
| Drift direction | **canonical is AHEAD of vendors** by 3 commits: `db2ec4c` (Admin_Form_Renderer + consumer-gateway integration guide), `1bd464e` (per-checkout return_url override), `dcfd0bc` (get_gateway_views + render_field) — none of these are shipping in production yet |
| Active consumers | `wpconnectpress` (slug=wpconnectpress, prefix=wpcp), `wp-career-board-pro` (slug=wp-career-board, prefix=wcb) |
| Empirically verified | three plugins active simultaneously → zero PHP fatals, separate ledger tables (`wp_wpcp_credit_ledger`, `wp_wcb_credit_ledger`), separate REST namespaces, both consumers registered in the shared `Registry` singleton |
| Schema contract violation | Career Board Pro ships its own `CREATE TABLE` (`core/class-pro-install.php:441`) with columns `employer_id`/`post_id`, which pre-empts the SDK's `Ledger::maybe_create_table()`. All SDK queries then fail because they reference the standard `user_id` column. **Decision: Option B (uniform schema).** Career Board Pro migrates to SDK's standard columns; SDK keeps a single canonical schema; CI regression tests lock the contract. |
| Test coverage | 4 unit tests in canonical (`tests/Gateways/`): Idempotency, PendingCheckouts, GatewayEvent, SignatureVerifier. No Registry / Ledger / REST / Versions / Adapter / Template tests yet |
| Compatibility matrix | not documented |
| Cross-plugin features | none (no transfer, no unified view, no shared admin) |

---

## Phase 1 — Foundation (Weeks 1-3, "stop the bleeding")

**Goal:** lock canonical as the actual source of truth + cover the multi-plugin guarantees with CI tests + cut a fresh tag that brings the production vendors current.

### Deliverables

1. **Cut SDK v1.3.0 release** — the 3 post-1.2.0 commits add new public API (Admin_Form_Renderer, get_gateway_views(), render_field(), per-checkout return_url override) so by strict semver this is a minor bump, not a patch.
   - Add CHANGELOG.md with the diff summary + breaking-change-free note.
   - `git tag -a v1.3.0` once the user_type column bug is fixed + Phase 1 tests land.
   - Re-vendor into WPConnectPress + wp-career-board-pro. Each consumer ships a minor release picking up v1.3.0.
   - **Renumber downstream features in this plan accordingly:** Transfer API moves to v1.4.0, Unified Wallet stays v2.0.

   **v1.3.0 deliverables:**
   - Existing 3 post-1.2.0 commits (Admin_Form_Renderer, return_url, get_gateway_views).
   - Versions tests (already landed in `a3c738b`).
   - **Schema contract enforcement** — codified in README + locked by CI tests (`tests/Ledger/SchemaContractTest.php`). Single canonical schema: `user_id`, `item_id`, no per-consumer column renaming.
   - **Career Board Pro migration spec** — `docs/MIGRATION-1.3.0-career-board.md` documents the one-shot column rename + install-script change consumer plugins must adopt before bundling v1.3.0. ✅ Migration shipped in wp-career-board-pro 1.1.1.
   - **Server-authoritative pricing (security fix, issue #2)** — `src/Gateways/Pricing.php` + 12 regression tests. Closes the client-supplied-price_cents tampering vulnerability. Consumer-side playbook: `docs/MIGRATION-1.3.0-pricing.md`. Site-owner setup guides: `docs/SETUP-STRIPE.md`, `docs/SETUP-PAYPAL.md`.
   - **CHANGELOG.md** with explicit notes for both clarifications + the security fix.

2. **Choose the vendor sync mechanism**
   - **Recommendation:** composer with a path/VCS repository.
   - Each consumer plugin's `composer.json` requires `wbcom/credits-sdk: ^1.2`. CI pins a lockfile. Release build copies `vendor/wbcom/credits-sdk/` into the distributed zip.
   - Alternative: bash script `bin/sync-sdk.sh` that rsyncs from canonical → vendor on a versioned tag.
   - Git submodule rejected — submodules break for less-technical contributors and conflict with WordPress.org-style distribution.

3. **Document the consumer-side compatibility matrix** (README table):

   ```
   | Consumer plugin            | Min SDK | Tested SDK | Notes                  |
   |----------------------------|---------|------------|------------------------|
   | wpconnectpress             | 1.2.0   | 1.2.0      | slug=wpconnectpress    |
   | wp-career-board-pro        | 1.2.0   | 1.2.0      | slug=wp-career-board   |
   ```

4. **Pre-commit hook + GitHub Actions CI for the SDK repo** — PHPStan level 6, PHPCS WordPress Core ruleset, PHP 8.1+ compat.

5. **First wave of regression tests** — locks the multi-plugin guarantee and the bugs we already know about:

   - `tests/Registry/MultiConsumerTest` — boot two consumers, assert separate tables created, separate REST routes registered.
   - `tests/Versions/IdempotentRegisterTest` — register `1.2.0` twice → second returns `false`, no fatal.
   - `tests/Versions/LatestWinsTest` — register `1.1.0` + `1.2.0` callbacks → only `1.2.0` runs.
   - `tests/Ledger/PrefixIsolationTest` — `Credits::deduct('wpcp', user_id, 5)` and `Credits::deduct('wcb', user_id, 5)` → each plugin's table has 1 row, balances are independent.
   - `tests/REST/UserTypeColumnMapTest` — regression for the bug surfaced 2026-05-11. Register a consumer with `user_type='employer'` → balance route queries the renamed column, not hardcoded `user_id`.

### Exit criteria

- Canonical repo === shipping code.
- 5+ new tests green in CI.
- The `WHERE user_id` bug is fixed and the regression locked.
- Every consumer plugin documents which SDK version it bundles.

---

## Phase 2 — Hardening (Weeks 4-8, "make it boring")

**Goal:** the SDK becomes invisible infrastructure. Failures are caught upstream, not by customers.

### Deliverables

6. **Full unit coverage of `src/`** — target ~80% line coverage of every class.
   - Registry / Versions / Ledger / Credits / REST / Adapters / Gateways
   - Each adapter (WC, WCS, WCM, PMPro, MemberPress) gets a mocked-platform contract test.

7. **Cross-plugin integration test suite** (`tests/Integration/`)
   - Boots a minimal WP test environment.
   - Installs and activates TWO mock consumer plugins.
   - Asserts: no PHP fatal/notice; both consumers in `Registry::instance()`; both ledger tables created; REST routes for both namespaces respond 200; `Credits::deduct()` on consumer A does not touch consumer B's table; deactivating consumer A keeps consumer B working.
   - This is the **highest-value test pack** — it locks the multi-plugin coexistence story.

8. **SDK Contract Test** — single PHPUnit file every consumer plugin can drop into its own `tests/`.
   - Exercises the consumer's `Registry::register()` call.
   - Asserts prefix uniqueness, slug uniqueness, REST routes present, ledger table created, no fatals.
   - Each plugin's CI runs the contract test against its checked-in code. Failure = blocking release.

9. **Static analysis: PHPStan level 8 on `src/`** — catch soft typing issues before runtime.

10. **Compatibility CI matrix** — GitHub Actions runs the SDK test suite against PHP 8.1, 8.2, 8.3 and WP 6.7, 6.8, 6.9. Fail any combination → PR blocked.

11. **Observability primitives in the SDK itself**
    - `Wbcom\Credits\Diagnostics::report()` — returns array of registered consumers, ledger row counts, recent gateway events, SDK version. Surfaced in WP Site Health.
    - Single canonical logger: every SDK error/warning flows through `WP_Error` + a known `wbcom_credits_log` action so each consumer can choose its log target.

### Exit criteria

- 80%+ line coverage on `src/`.
- Cross-plugin integration suite green in CI.
- SDK Contract Test adopted by all 5+ consumer plugins.
- Site Health surfaces SDK state to admins.

---

## Phase 3 — Cross-plugin features (Weeks 9-16, "the value unlock")

**Goal:** turn the SDK from "5 plugins share scaffolding" into "5 plugins behave like one product."

### Deliverables

12. **Transfer API (SDK 1.4.0)**
    - `Credits::transfer(from_slug, to_slug, user_id, amount, reason)`
    - Single-transaction `$wpdb->query('START TRANSACTION')` for atomic dual-ledger writes.
    - Shared `transfer_group_id` column on both ledgers for audit/refund.
    - Action `wbcom_credits_transferred` fires after success.
    - Admin UI: "Transfer credits" page under WP Tools, lists registered consumers via `Registry::instance()`.
    - REST endpoint: `POST /wbcom-credits/v1/transfer` (cross-consumer, requires `manage_options`).

13. **Unified-wallet mode (SDK 2.0, opt-in flag)**
    - One shared `wp_wbcom_credit_wallet` table keyed by `user_id`, total balance.
    - Each consumer still has its own ledger for line-item history; balance comes from the wallet.
    - Migration script for existing per-plugin balances: sum each user's per-plugin balance → seed wallet → keep ledgers as history.
    - Backward-compatible: SDK 1.4 consumers keep working in per-plugin-pool mode; opt-in flag flips to wallet mode.

14. **Cross-plugin admin dashboard**
    - One settings screen under WP Admin → "Credits". Lists every consumer plugin, ledger row count, recent transactions, links to per-consumer settings.
    - Filterable transaction log across all consumers.
    - "Adjust credits for user" tool (admin-side credit grant + transfer).

15. **Cross-plugin events**
    - Plugin A's `wbcom_credits_deducted` can trigger Plugin B's `Credits::topup()` via a documented bridge.
    - Use case: customer redeems Career Board credits for a free Connect meeting.

16. **Unified gateway settings**
    - Stripe / PayPal keys configured ONCE in the SDK admin; all consumers share.
    - Per-consumer override available.

### Exit criteria

- Transfer API shipped and adopted by support team for "move my credits" requests.
- Cross-plugin admin dashboard live.
- ≥1 consumer plugin opts into unified-wallet mode and runs in production for 30 days.

---

## Phase 4 — Portfolio governance (Weeks 17+, "scale it past 10 plugins")

### Deliverables

17. **Portfolio-level dashboard**
    - Single internal page listing every consumer plugin: current SDK version, last consumer-side smoke pass date, contract-test status, open SDK-related bugs.
    - Drift alarm: any plugin whose vendored SDK version is older than 2 minor releases triggers a "must upgrade" ticket.

18. **SDK release process**
    - SDK tag → automated PR opened against each consumer plugin updating vendored copy.
    - Consumer plugin's CI runs full smoke (existing QA pipeline).
    - Auto-merge on green; manual review on red.
    - Coordinated release wave for breaking changes.

19. **Public consumer onboarding doc**
    - "Adding the Credits SDK to a new plugin" step-by-step.
    - Registration shape, REST namespace conventions, table-prefix rules, smoke-test snippet.
    - 1-hour onboarding target.

20. **Long-term schema governance**
    - Migration framework inside the SDK (`Wbcom\Credits\Migrations`).
    - Each schema change ships as a migration callable; SDK runs them on `plugins_loaded` if a `wbcom_credits_db_version` option is behind.
    - Backfill scripts for old data shapes.

21. **Performance/scale baseline**
    - Single ledger with 10M rows: balance query stays sub-50ms.
    - Cursor pagination on history routes.
    - Indexes verified.

### Exit criteria

- 10+ plugins ship the SDK, drift alarm = 0 in steady state.
- New consumer plugin onboarding = 1 working day.
- SDK release cadence is predictable (monthly or quarterly).

---

## Cross-cutting concerns (every phase)

- **Security** — every SDK release runs through `wppqa_check_plugin_dev_rules` and the existing security audit. Gateway webhook signature verification has dedicated coverage.
- **GDPR** — the SDK exposes `Credits::export_user_data($user_id)` + `Credits::erase_user_data($user_id)` honored by every consumer's WP privacy hooks.
- **i18n** — SDK strings use `wbcom-credits-sdk` text domain; each consumer loads its own translations for consumer-side strings.
- **Documentation** — every SDK release ships a changelog + integration-guide diff. Consumer plugins link to the SDK README.

---

## Risks

| Risk | Mitigation |
|---|---|
| Canonical repo drift recurs | Phase 1 deliverable + CI alarm |
| Breaking change in SDK 2.0 strands plugins on 1.x | LTS branch for 1.x, 12-month support window after 2.0 GA |
| Unified-wallet migration corrupts historical balances | Snapshot+rollback in migration, dry-run mode, integration test on production data clone |
| New consumer plugin registers wrong prefix/slug | Phase 1 contract test catches this in CI before release |
| Multi-version coexistence breaks at SDK 3.0 (loader rewrite) | Phase 2's `ClassLoaderFillInTest` runs against all bundled versions, locks the contract |

---

## Decisions required to start Phase 1

1. **Vendor sync mechanism** — composer path repository, git submodule, or rsync script? Recommended: composer.
2. **Where the canonical repo lives long-term** — keep at `~/projects/wbcom-credits-sdk` (personal) or move to a Wbcom GitHub org?
3. **Versioning policy** — strict semver (current: 1.x → 2.0 for any BC break), or marketing-version-aligned with the consumer plugins?

---

## Phase progress

| Phase | Status | Notes |
|---|---|---|
| Phase 1 — Foundation | in progress (started 2026-05-11) | Canonical-to-1.2.0 reconciliation underway |
| Phase 2 — Hardening | not started | |
| Phase 3 — Cross-plugin features | not started | |
| Phase 4 — Portfolio governance | not started | |
