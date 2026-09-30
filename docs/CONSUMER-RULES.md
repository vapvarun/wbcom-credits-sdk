# Rules for every plugin that uses the Credits SDK

These rules apply to every consumer listed in [CONSUMERS.md](../CONSUMERS.md)
(WB Ad Manager Pro, WB Listora, WP Career Board Pro, WPConnectPress) and to any
new one. They exist because consumers kept fixing the same money bugs in their
own code, and the fixes drifted apart. See [AUDIT-2026-09-27.md](AUDIT-2026-09-27.md).
For the how-to (bundle, register, take money in, charge, refund, report), see
[INTEGRATION-GUIDE.md](INTEGRATION-GUIDE.md).

## 1. Fix it upstream, never in your copy
- `libs/wbcom-credits-sdk/` is a vendored copy. Never edit it by hand.
- A bug in SDK behaviour is fixed in this repo:
  1. branch;
  2. add a test;
  3. run `bin/audit.sh`, which must be green;
  4. open a PR, merge, and tag the version;
  5. only then re-bundle.
- The consumer's own commit only bumps the bundle and `.bundled-from`
  (the upstream sha).
- If a consumer truly needs a stop-gap before the release, put it in the
  consumer's bridge class. Mark it `// SDK-WORKAROUND: <issue/PR> - remove when
  bundling >= X.Y.Z`, and remove it in the release that bundles the fix.

## 2. Use the public API, not the tables
- Write through `Credits::*` (or `*_money()` in money mode). Never call
  `Ledger::insert()` or write to `{prefix}_credit_ledger` yourself: direct
  writes skip the hooks, the cache and the units rule.
- Read through `Credits::*`: `query_ledger()`, `count_ledger_rows()` and
  `sum_ledger()` filter by user, reason, reference and UTC date range.
  If you need a query they do not offer, add it to the SDK first.
- Pass a `reason` and `reference` when you top up or adjust
  (`purchase` + order id, `admin_adjust`, `gateway_refund`).
- Give money back for an item with `Credits::credit()` (1.9.2): it does
  not fire the purchase event `wbcom_credits_topped_up`.
- Totals for many users, or per reason: `sum_ledger_grouped()`. One row:
  `get_ledger_row()`.
- Existing direct reads and writes are debt. List them in the consumer's
  `docs/standards/credits-sdk.md` and remove them as the SDK gains the API.

## 3. One unit rule
- The ledger stores integers: credits, or minor units (cents) in money mode.
- Money consumers register `'money' => array( 'currency' => … )` once.
- Money consumers convert major units only through `Money::to_minor()` /
  `to_major()` or the `*_money()` methods, never with `* 100`. Some
  currencies have 0 or 3 decimals.
- Every function that takes or returns an amount says its unit in the
  docblock: `ledger units`, `minor units` or `major units`.

## 4. Spend safely (1.9.0+)
- Charge with an approval step: `try_hold()`, keep the id, then
  `settle_hold( $id )` or `release_hold( $id )`.
- Charge per event, with no approval step: `spend()`.
- Both check the balance under the user's lock. Never check with
  `get_balance()` and then write: two requests both pass.
- Cancel by id (`cancel_hold_by_id()`). `deduct()` and `cancel_hold( $item_id )`
  remain for old callers only.

## 5. Time
- Every row is stored in UTC, written by PHP with `gmdate( 'Y-m-d H:i:s' )`.
  Never use the column default: MySQL's clock follows the server time zone.
- Show dates in the site time zone (`wp_date()`, or `get_date_from_gmt()`).
- Filter by date by converting the site-time range to UTC first.

## 6. Gate on what you call
- Check `Credits::checkout_enabled()` / `can_purchase()` before showing any
  buy UI.
- Hook `wbcom_credits_checkout_enabled` to the plugin's own on/off switch.
- Guard every SDK call on the methods it uses (a `…_ready()` helper), never on
  `class_exists()` alone. An older copy may have won the election.

## 7. Keep in step
- Every consumer bundles the latest tagged release on its next release. See
  CONSUMERS.md for bundle rules 1-5.
- Before tagging a consumer release:
  - `.bundled-from` matches a tag or a merged master sha;
  - `diff -r` against that sha is empty;
  - the consumer's own test suite passes with the new bundle.
- Update the consumer's row in CONSUMERS.md in the same PR that bumps the SDK.
- Bundle only tagged releases, once per product release, and freeze the version before the product's QA round. See [RELEASE-POLICY.md](RELEASE-POLICY.md).

## 8. The SDK renders nothing (owner rule, 2026-09-30)
- Every screen, form, template, script, notice and user-visible sentence
  about credits belongs to your plugin, in your plugin's text domain. The SDK
  gives you data, `sanitize()` / `save()` calls, REST routes and error codes.
- Do not call an SDK `render*()` method, SDK template or `checkout.js` in new
  code. They are deprecated from 1.10.0 and removed in 2.0.0.
- Never show an SDK `WP_Error` message to a user. Map its `code`
  ([ERROR-CODES.md](ERROR-CODES.md), from 1.10.0) to your own string.
- Never add UI to the SDK. A surface you need is built in your plugin.
- Plan, inventory and per-consumer checklists: [HEADLESS-PLAN.md](HEADLESS-PLAN.md).

## Checklist for a PR that touches credits
- [ ] No edit under `libs/wbcom-credits-sdk/` except a full re-bundle.
- [ ] No new `Ledger::insert` / `Ledger::table_name` / raw ledger SQL.
- [ ] Every amount's unit is named, and money goes through `Money`.
- [ ] Spends use `try_hold()` → `settle_hold()` / `release_hold()`, or `spend()`.
- [ ] Dates are written as UTC from PHP, and shown in the site time zone.
- [ ] Buy UI gated on `can_purchase()`, SDK calls guarded by the `…_ready()` helper.
