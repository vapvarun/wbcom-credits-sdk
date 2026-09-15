# Who bundles this SDK

Every consuming plugin ships its own copy of the SDK, and the loader fills in
only the classes not already in memory — so on a site running several Wbcom
plugins, **the copy loaded first owns `\Wbcom\Credits\*` for the whole
request**, whatever version it is. A single consumer left behind on an old copy
therefore breaks every other consumer on that site, not itself.

That makes "which version is bundled where" a security and correctness property
of the SDK, not of the individual plugin. This file is the register. Update it
in the same change that bumps a consumer's bundle.

Last audited: 2026-09-15, against canonical `master` (1.6.0).

## Consumers

| Plugin | Repo | Bundle path | Loads its copy | Bundled | Guard |
|---|---|---|---|---|---|
| WB Ad Manager Pro | `vapvarun/wb-ad-manager-pro` | `libs/` | plugin-file include | 1.6.0 | `Credits_Bridge::sdk_money_ready()` |
| WB Listora (free) | `wbcomdesigns/wb-listora` | `libs/` | plugin-file include | 1.6.0 | `wb_listora_credits_ready()` |
| WB Listora Pro | `wbcomdesigns/wb-listora-pro` | — consumes Free's copy | — | — | `wb_listora_credits_ready()` |
| WP Career Board Pro | `vapvarun/wp-career-board-pro` | `libs/` | plugin-file include | 1.6.0 | none — legacy API only |
| WPConnectPress | `vapvarun/WPConnectPress` | `libs/` | `plugins_loaded` (10) | 1.6.0 | none — legacy API only |

Not consumers, checked and clear: WB Ads Rotator with Split Test (free),
WP Sell Services (free + pro), Woo Sell Services, Jetonomy, Learnomy.

## Rules

1. **One version across the portfolio.** A consumer that bundles an older copy
   is a hazard to every other consumer, so bumping one means bumping all. Check
   both the released branch and the active development branch — a fix that only
   lands on `main` leaves the next release shipping the old copy.
2. **Commit the bundle.** The SDK is shipped code, not a dev dependency. A
   bundle that is gitignored or left untracked ships whatever happened to be on
   the builder's disk — WPConnectPress shipped 1.3.0 that way while its repo
   tracked only `composer.lock`.
3. **Load it while the plugin file runs**, not on `plugins_loaded`. Late loaders
   lose the race to any plugin that loads early, and then call their own newer
   API on someone else's older class.
4. **Never gate on `class_exists( '\Wbcom\Credits\Credits' )` alone.** The class
   existing says nothing about its version. Gate on the methods you actually
   call (see the Guard column) so a skewed site degrades instead of fataling.
5. **Tag every release.** 1.6.0 shipped untagged, so consumers bundled drifting
   snapshots of `master` that all called themselves 1.6.0 — including one that
   predated the fix for crediting unpaid COD orders.

## Field history

- **Support ticket 41719 (2026-09-14).** WB Ad Manager Pro 3.1.0 fataled on
  every credit charge on a site also running WB Listora ≤1.6.x. Listora bundled
  1.3.0 and loaded it at file-include time; Ad Manager Pro loaded its 1.6.0 on
  `plugins_loaded:20` and lost, then called `Credits::forget_balance()` on the
  1.3.0 class. Fixed in Ad Manager Pro 3.1.1 (early load + readiness gate) and
  WB Listora 1.8.0 (readiness gate on all 23 call sites).
