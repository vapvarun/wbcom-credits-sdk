# Release policy: how SDK versions reach a product

Owner rule, 2026-10-03. It applies to every Wbcom plugin that bundles this SDK.

## Why this exists

During WB Ad Manager 3.2.0, Pro bundled five SDK versions (1.8.0, 1.8.1, 1.9.0, 1.9.1, 1.9.2) in about a week, often one bump per fix. Each bump landed inside the product's release test window. Every charge, refund and top-up path then had to be re-tested, and a passed money test was out of date within a day. Several products bundle this SDK, so staggered bumps multiply the combinations to test.

Bumping the version for every fix is right for a library: consumers can see exactly what they have. The problem is bundling each bump into a product mid-release.

## The rules

1. **Batch fixes into tagged releases.** Fix, test and tag here first, on this repo and its board. A product bundles only a **tagged** SDK version, never an untagged master sha mid-cycle, and does it **once per product release**, not after each fix.
2. **Freeze before QA.** When a product enters its release test round, its bundled SDK version is locked. Only an owner-approved release blocker can change it. Everything else waits for the product's next patch release.
3. **SDK QA once per tag.** Before a tag is bundled anywhere, run these here:
   - every money path;
   - a concurrent-charge race (two parallel spends against one balance: exactly one wins, and the balance is never negative);
   - the refund cap (unspent balance only, never negative, idempotent);
   - schema upgrade idempotency from the oldest supported schema.

   Each consumer then re-tests only its own integration.
4. **A bundle bump is its own card.** In the consumer's board, a new SDK bundle arrives as its own card in Ready for Testing. It is never a comment on a card that is already Done.
5. **Record it.** Update the consumer's row in [CONSUMERS.md](../CONSUMERS.md) and its `.bundled-from` in the same PR, as [CONSUMER-RULES.md §7](CONSUMER-RULES.md#7-keep-in-step) already requires.

## Current freezes

| Consumer | Release | Frozen at | Since |
|---|---|---|---|
| WB Ad Manager Pro | 3.2.0 | 1.9.4 | 2026-09-28 |

Remove a row when that product release ships.

WB Ad Manager Pro 3.2.0 was first frozen at 1.9.2. It moved to **1.9.4**
with owner approval (2026-09-28): 1.9.3 fixes a release blocker (a site
running Pro next to WB Listora's SDK 1.7.2 fataled on every request), and
1.9.4 differs from 1.9.3 only in Consumer charging, which Pro never calls.
Nothing else changes before 3.2.0 ships.
