# Review handoff: Wbcom Credits SDK and its use in WB Ad Manager Pro

**For:** reviewers (people or agents) checking the SDK with fresh eyes and filing cards for gaps.
**State at handoff (2026-09-27):** SDK master `daf1f8c` = tag `v1.9.1` plus docs. WB Ad Manager Pro bundles exactly that on its `3.2.0` branch (commit `7d90b30c`).

Your job is to **find and file**, not to fix. Do not push to this repo or to any plugin repo. Every finding becomes a Basecamp card (section 6); the owner decides what gets fixed and when.

---

## 1. What the SDK is

A PHP library each Wbcom plugin bundles in `libs/wbcom-credits-sdk/` to run a per-user balance: credits (tokens) or money. It covers:
- an append-only ledger;
- holds, spends and refunds;
- Stripe and PayPal checkout (billing, coupons, tax, receipts);
- adapters that credit purchases from WooCommerce, PMPro, MemberPress, WooCommerce Subscriptions and Memberships;
- credit expiry;
- REST routes;
- a copy election, so several bundled copies can share one site.

Consumers today: WB Ad Manager Pro (1.9.1), WP Career Board Pro (a 1.9.0 development build), WB Listora (1.7.2), WPConnectPress (1.7.0). See [CONSUMERS.md](CONSUMERS.md).

## 2. Read these first (in this order)

| File | Why |
|---|---|
| [docs/AUDIT-2026-09-27.md](docs/AUDIT-2026-09-27.md) | The eight design gaps found on 2026-09-27 and their status. Don't re-file these; check that the fixes hold. |
| [CHANGELOG.md](CHANGELOG.md) (1.9.1, 1.9.0) | What changed and why, bullet by bullet. Every "Changed" line is a claim to verify. |
| [docs/CONSUMER-RULES.md](docs/CONSUMER-RULES.md) | What a consumer must and must not do. |
| [docs/INTEGRATION-GUIDE.md](docs/INTEGRATION-GUIDE.md) | How a plugin integrates. Every snippet should run as written; a wrong one is a finding. |
| [CONSUMERS.md](CONSUMERS.md) | Who bundles what, and the bundling rules. |

## 3. Get it running

```bash
git clone https://github.com/vapvarun/wbcom-credits-sdk && cd wbcom-credits-sdk
composer install
bash bin/audit.sh          # lint, PHPUnit, PHPStan, class map, version and doc checks, API snapshot: expect 11/11
vendor/bin/phpunit         # 226 tests, on an in-memory $wpdb fake (tests/Support/FakeWpdb.php)
php tests/loader-election-check.php
```

**Limitation:** the unit tests run on a fake database. MySQL behaviour (named locks, `FOR UPDATE`, transactions, ALTERs) is only proven through a consumer running on real MySQL. See WB Ad Manager's `tests/pro/test-credits-sdk-ledger-integrity.php` and `tests/pro/test-credits-charge-concurrency.php`, in the free plugin repo `wb-ads-rotator-with-split-test`, which holds Pro's tests. Treat any MySQL-only claim the fake can't prove as unverified until you check it.

## 4. What to check in the SDK

Work area by area. File one card per gap.

**Money integrity** (`src/Ledger.php`, `src/Credits.php`)
- A hold is open while no row carries its id in `hold_id`. Can a hold be settled, released or cancelled twice? Can a settled hold ever be undone?
- Rows written before 1.9.0 have no `reason` and are read by net amount per item in `Ledger::open_holds()`. Try edge cases such as partial releases, several legacy holds on one item, and mixed old and new rows.
- `try_hold()` and `spend()` check the balance under `Ledger::with_user_lock()`, a MySQL named lock plus a `FOR UPDATE` read (1.9.1). What happens when:
  - the lock times out;
  - the calls nest;
  - a caller runs its own transaction;
  - a listener opens a transaction inside an SDK transaction (MySQL has no nested transactions; `Ledger::begin()` counts depth for SDK calls only)?
- `topup_once()` and the gateway paths claim and credit in one transaction. Look for a path that still claims outside it, and one that does HTTP inside it.

**Units** (`src/Money.php`, `src/Support/Currencies.php`)
- The ledger holds minor units in money mode. Look for any path that mixes major and minor units, uses `* 100`, or floors cents: gateways, adapters, REST, `Consumer`, expiry, low-balance threshold, receipts.
- Check zero- and three-decimal currencies (JPY, KWD) end to end.

**Schema and upgrades** (`src/Registry.php` schema 6, `Ledger::maybe_upgrade()`, `Transaction_Log`, `Processed_Events`)
- Does an old table from each earlier version reach the current schema?
- What happens on a large ledger: are the ALTERs safe to run on the first request after an update?

**Gateways and checkout** (`src/Gateways/*`, `src/Billing.php`, `src/Receipt.php`, `src/Expiry.php`, `assets/js/checkout.js`)
- Webhook signatures, amount and currency cross-checks, duplicate deliveries, refunds, the redirect claim, the reconcile sweep, free (100% coupon) orders.
- Receipt access: only the buyer and admins.
- Coupon limits under concurrency.

**Copy election and guards** (`wbcom-credits-sdk.php`, `src/Versions.php`)
- Several copies of different versions on one site; late includes.

**Docs vs code.** Every claim in README, CHANGELOG, INTEGRATION-GUIDE and CONSUMER-RULES must match the code.

**Scale.** Every list or report query must be paged and indexed. Look for any N+1 or unbounded read.

## 5. How WB Ad Manager Pro uses it (check this too)

Repos: `wb-ad-manager-pro` (code) and `wb-ads-rotator-with-split-test` (Pro's tests are in `tests/pro/`), both on branch **`3.2.0`**. Local site: `wp-ads.local`.

**Registration:** in `includes/Core/class-credits-bridge.php`, `register_consumers()` registers:
- slug `wbam-pro`, prefix `wbam` (tables `{wp}wbam_credit_ledger` and friends);
- money mode with the site currency (`wbam_get_currency_code()`);
- a `return_url` pointing at the advertiser dashboard's Balance tab.

**Readiness guard:** `Credits_Bridge::sdk_money_ready()` requires `is_money`, `topup_money`, `adjust_money`, `forget_balance` and `spend`. When an older copy wins the election, Pro shows an admin notice.

**Where Pro touches the SDK:**

| Pro code | SDK call | Notes |
|---|---|---|
| `Credits_Bridge::charge()` | `Credits::spend()` | Every paid action: ad package, campaign reserve, classified listing/upgrade/bump/featured, membership. `$force` maps to `allow_overdraft` (compensating entries only). |
| `Credits_Bridge::credit()` | `Ledger::insert()` directly (`topup`, reason `refund`) | Known debt. `Credits::topup()` would fire `wbcom_credits_topped_up`, which Pro maps to a "funds added" email and to top-up revenue. |
| `Credits_Bridge::topup()` / `adjust()` | `Credits::topup_money()` / `adjust_money()` | Admin add / adjust funds. |
| `Credits_Bridge::get_balance()` etc. | `Ledger::get_balance()`, `Credits::get_balance()`, `get_ledger()` | `get_balance_minor()` reads `Ledger::get_balance()` directly. |
| Admin Transactions, wallet totals, total spent, reserved | Raw SQL on the ledger table | Known debt. Needs grouped totals and counts by reason from the SDK. |
| `record_paid_topup()`, `record_gateway_refund()` | Raw SQL joins with Pro's `wbam_revenue` table | Known debt. Needs the ledger id on `wbcom_credits_topped_up`. |
| WooCommerce order notes | `Processed_Events::exists()` / `claim()` | Pro reads and claims SDK adapter claim rows. Check this doesn't race the adapter's own claim inside `topup_once()`. |
| Wallet "awaiting payment" | `Pending_Checkouts::for_user()` | |
| Settings > Payments | `Gateway_Registry::for_slug()`, pack and gateway renderers | `includes/Admin/class-credits-settings.php`. |
| Upgrade | `Ledger::maybe_create_table( 'wbam' )` | `includes/Core/class-installer.php`. |
| `Revenue_Ledger` (Pro's own table) | Twin rows keyed by `ledger_id`; backfills join the SDK ledger | Pro keeps revenue separately. Ask whether the SDK's `reason` / `reference` columns now make this redundant. |

**Events Pro listens to:**
- `wbcom_credits_topped_up`: revenue row, then the "funds added" email;
- `wbcom_credits_gateway_refund`: revenue reversal;
- `wbcom_credits_refunded`: WooCommerce refunds and order notes;
- `wbcom_credits_purchase_url`: points buyers at the Balance tab.

Pro does **not** use `wbcom_credits_low`; it sends its own low-balance email from `wbam_advertiser_balance_debited`.

**Pro's own statement of its debt:** `wb-ad-manager-pro/docs/standards/credits-sdk.md`. Check it's complete: every direct ledger access in Pro should be listed there.

What to look for in Pro:
- any money path that bypasses `Credits_Bridge`;
- any balance check that isn't under the SDK lock;
- unit mix-ups between Pro's `to_minor()` / `to_major()` and the SDK's `Money`;
- listeners that don't check `$slug`;
- UTC handling of SDK dates. Pro converted its old ledger rows to UTC in migration `4.3.24`, `Installer::continue_sdk_utc_migration()`.

## 6. Filing cards

Basecamp project **"Wbcom Credits SDK"** (id `49046587`). Columns:

| Column | Id | Use |
|---|---|---|
| Triage | `10344574141` | **File every new finding here.** The owner moves it on. |
| Bugs | `10344574143` | Confirmed, to fix |
| In Development | `10344574144` | Being fixed |
| Ready for Testing | `10344574403` | Fixed, to verify |
| In Testing | `10344574410` | Being verified |
| Done | `10344574145` | |
| Not now | `10344574142` | Parked |

**Before filing, check what's already there:**
- the eight audit findings (Ready for Testing);
- "Consumer costs are whole units of money" (`10344641016`, open);
- one "Adopt SDK 1.9.0" card per consumer.

A finding about WB Ad Manager's own code goes on the **WB Ad Manager** board instead (project `44982066`, Triage column `9334950390`), with a link to the SDK card if there is one.

**Card title:** `[SDK x.y.z] <what is wrong, in plain words>`, or `[WBAM 3.2.0] ...`.
**Card body**, every field required:
- **Where:** file:line at commit `daf1f8c` (SDK) or the Pro/Free commit.
- **What happens:** exact steps or input, then what you saw versus what should happen.
- **How you know:** reproduced (how), a test you wrote (paste it), or code reading only (say so).
- **Who it costs:** which consumers and which sites (money lost, double charge, blocked purchase, wrong report).
- **Fix pointer:** the smallest fix you'd suggest. Optional, but helpful.

One finding per card; group only true duplicates. Don't file style nits as bugs. A missing test for a money path is a valid card.

## 7. Decisions already made (don't re-open them as bugs)

- **One SDK version across all consumers.** A bundle is a byte-identical copy of a tag or merged master sha, recorded in `.bundled-from`.
- **Fixes go upstream first,** then re-bundle; nobody edits a bundled copy.
- **The SDK keeps WP Career Board Pro's 1.9.0 API order:** `topup( ..., $note, $expires_at, $reason, $reference, $item_id )`, and the Consumer methods `reserve_item()`, `settle_item()`, `release_item()`, `reprice_item()`, `record()` and `set_state()`.
- **Gateway refunds** revoke at most the buyer's unspent balance (1.8.0 refund policy).
- **Dates** are stored in UTC and shown in the site time zone.
- **In WB Ad Manager 3.2.0, reports keep their raw SQL.** They are listed as debt, not rewritten before the release.
- **`Credits::refund()`'s `wbcom_credits_refunded` event keeps `reason = hold_refund`** for compatibility. The ledger row's `reason` column is the precise value.
