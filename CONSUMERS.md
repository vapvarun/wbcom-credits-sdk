# Who bundles this SDK

Every consuming plugin ships its own copy of the SDK, because a customer may
install just one of our plugins and there is no shared dependency manager in
WordPress. PHP still has exactly one `\Wbcom\Credits\Credits` per request, so
the copies have to agree on which of them gets to be it.

**From SDK 1.7.0 they elect one.** Each copy announces its directory and
version at include time and loads nothing; the first class anyone touches is
served from the highest version announced, and every later class comes from
that same directory. A stale bundle announces, loses, and supplies nothing.

**Copies from 1.6.0 and earlier still load eagerly**, so one of those can still
win on load order and hand a newer consumer a class without the methods it
calls. That is what consumers' readiness checks are for (Guard column), and
why bundles should still be kept current rather than left to the election.

Last audited: 2026-09-15, against canonical `master` (1.7.0).

## Consumers

| Plugin | Repo | Bundle path | Loads its copy | Bundled | Guard |
|---|---|---|---|---|---|
| WB Ad Manager Pro | `vapvarun/wb-ad-manager-pro` | `libs/` | plugin-file include | 1.7.0 | `Credits_Bridge::sdk_money_ready()` |
| WB Listora (free) | `wbcomdesigns/wb-listora` | `libs/` | plugin-file include | 1.7.0 | `wb_listora_credits_ready()` |
| WB Listora Pro | `wbcomdesigns/wb-listora-pro` | — consumes Free's copy | — | — | `wb_listora_credits_ready()` |
| WP Career Board Pro | `vapvarun/wp-career-board-pro` | `libs/` | plugin-file include | 1.7.0 | none — legacy API only |
| WPConnectPress | `vapvarun/WPConnectPress` | `libs/` | `plugins_loaded` (10) | 1.7.0 | none — legacy API only |

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
3. **Load it while the plugin file runs**, not on `plugins_loaded`. Since 1.7.0
   this no longer decides who wins, but announcing early is what guarantees a
   copy is in the election before the first class is touched.
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
