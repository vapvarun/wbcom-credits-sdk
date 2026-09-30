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

Last audited: 2026-09-27, against 1.9.4. Tags: v1.8.0, v1.8.1, v1.9.0, v1.9.1,
v1.9.2, v1.9.3, v1.9.4 (1.7.1 and 1.7.2 were folded into 1.8.0 and never tagged).

## Headless from 1.10.0

Owner rule, 2026-09-30: **the SDK renders nothing.** Every consumer takes over
the credit screens, templates, scripts and wording it shows, in its own text
domain, and the SDK's UI is removed in 2.0.0 once every consumer has shipped
the change. What each consumer has to do, and the release order that keeps
mixed-version sites from fataling:
[docs/HEADLESS-PLAN.md](docs/HEADLESS-PLAN.md).

## Consumers

| Plugin | Repo | Bundle path | Loads its copy | Bundled | Guard |
|---|---|---|---|---|---|
| WB Ad Manager Pro | `vapvarun/wb-ad-manager-pro` | `libs/` | plugin-file include | 1.9.4* | `Credits_Bridge::sdk_money_ready()` |
| WB Listora (free) | `wbcomdesigns/wb-listora` | `libs/` | plugin-file include | 1.7.2 (branch `1.9.0`; `main` 1.7.1), no `.bundled-from` | `wb_listora_credits_ready()` |
| WB Listora Pro | `wbcomdesigns/wb-listora-pro` | — consumes Free's copy | — | — | `wb_listora_credits_ready()` |
| WP Career Board Pro | `vapvarun/wp-career-board-pro` | `libs/` | plugin-file include | 1.9.5 (branch `1.8.0`, bundled 2026-09-29) | `JobCharge::consumer()` gates on `Registry::consumer()` |
| WPConnectPress | `vapvarun/WPConnectPress` | `libs/` | **never** (Credits feature removed in PR #117; nothing includes the loader) | 1.7.0, unused | — |

\* WB Ad Manager Pro 3.2.0 is frozen at 1.9.4 (RELEASE-POLICY.md). Adoption
for every other consumer is tracked in the Bugs column of the Basecamp project
"Wbcom Credits SDK" (one "Adopt SDK 1.9.4" card each, with the steps WB Ad
Manager Pro followed).

Not consumers, checked and clear: WB Ads Rotator with Split Test (free),
WP Sell Services (free + pro), Woo Sell Services, Jetonomy, Learnomy.

**Everyone moves to the latest tag on their next release; WB Ad Manager
Pro goes first.** Never ship a 1.9.0-1.9.2 bundle: next to an older copy
that loads first (WB Listora 1.7.2, alphabetically earlier) it fatals the
site. 1.9.3 fixes that. WB Listora, WP Career Board Pro and WPConnectPress are
now filed as Bugs on the Wbcom Credits SDK board. Their bundles
lack the 1.9.x integrity fixes: holds settled by id, balance checks under a
lock, claims and credits in one transaction, events after commit, atomic
refunds and coupon limits. What each must change is in the CHANGELOG
(1.9.0 "Changed", 1.9.2 "Changed"); the rest is additive.

**Before re-vendoring:** hook `wbcom_credits_checkout_enabled` to the
plugin's own "credits are sold here" switch. It gates the gateway checkout
route AND the WooCommerce / MemberPress / PMPro adapters' mapped products,
so a plugin that leaves it unhooked keeps selling while its credits feature
is off. WB Listora Pro hooks it to its Monetization toggle.

## Rules

These five cover bundling. How a consumer must USE the SDK (fix upstream,
public API only, one unit rule, safe spends, UTC, gating) is in
[docs/CONSUMER-RULES.md](docs/CONSUMER-RULES.md); every consumer follows it. A new
consumer starts from [docs/INTEGRATION-GUIDE.md](docs/INTEGRATION-GUIDE.md). The
design gaps behind the repeated fixes are in
[docs/AUDIT-2026-09-27.md](docs/AUDIT-2026-09-27.md).


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
