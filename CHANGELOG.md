# Changelog

All notable changes to the Wbcom Credits SDK are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the SDK follows [Semantic Versioning](https://semver.org/).

## [1.10.0] - September 2026

The SDK becomes headless: it renders nothing, and each consumer owns its credit screens and wording in its own text domain. This release **adds** the data-only API; nothing is removed, and nothing a consumer that has not migrated can see changes. The UI goes in 2.0.0. See [docs/HEADLESS-PLAN.md](docs/HEADLESS-PLAN.md).

### Added

- **`Gateways\Gateway_Settings`**: gateway settings as data. `views()` returns each gateway's label-free fields, stored values (secrets masked, with a `saved` flag) and webhook URL; `save()` sanitizes and stores posted values after the consumer's own nonce and capability check; `fields()`, `saved()`, `option_name()`, `webhook_url()`, `sanitize_value()`. `Admin_Form_Renderer` now uses its helpers, so there is one set of sanitizing rules.
- **`Billing::schema()`**: billing fields without labels.
- **`Countries::codes()` / `display_name()` and `Currencies::codes()` / `display_name()`**: names from PHP intl (CLDR), already correct in every language; the code is returned when intl is missing.
- **`Receipt::can_view()`** and the **`wbcom_credits_receipt_url`** filter, so a consumer serves receipts from its own page.
- **`wbcom_credits_purchase_unavailable`** action, fired where the WooCommerce, PMPro and MemberPress adapters block a credit purchase, for the consumer's own notice.
- **[docs/ERROR-CODES.md](docs/ERROR-CODES.md)**: every REST and webhook error code, including the eight `Pricing` codes the checkout route returns. Consumers show their own text per code. `ErrorCodesDocTest` fails when `src/` returns a code the doc does not list.
- **`Gateway_Settings::save()` stores a select value only when it is one of the field's options**, so a tampered form cannot store an unknown mode.
- Tests for `Receipt::can_view()` (buyer, other user, admin, guest, unknown slug or row) and the `wbcom_credits_receipt_url` filter.

### Deprecated (removed in 2.0.0)

- `Pack_Admin_Renderer::render()`, `Checkout_Settings::render()`, `Coupons::render()`.
- `Admin_Form_Renderer` (`render`, `render_field`, `handle_save`, `get_gateway_views`, `sanitize_for_settings_api`) and `templates/admin/gateways-section.php`.
- `Receipt::maybe_render()`, its `template_redirect` route and `templates/frontend/receipt.php`.
- The `wbcom-credits-checkout` script (`assets/js/checkout.js`).
- `Template::get()`.
- The `label` key of `Billing::fields()`, `Countries::all()` / `label()` and `Currencies::all()` / `name()` as display text.

## [1.9.5] - September 2026

### Fixed

- **A pending checkout can no longer be missed by the reconcile sweep because a shared index lost its row.** Every pending checkout is its own option, but `put()` also recorded it in one shared index option with a read-modify-write, and the hourly `Reconciler`, `for_user()` and `coupon_holds()` all enumerated checkouts through that index. Two buyers starting a checkout in the same instant could drop one row: if that buyer's webhook was then missed and they never returned, the sweep that exists to rescue the payment could not see it, and a coupon hold went uncounted. Entries are now found by option name (prefix plus the 32-character md5, oldest first, bounded by `LIMIT`), so nothing has to be recorded when an entry is written and `put()` no longer reads or writes a shared option. The pre-1.9.5 `index` option is deleted the first time a `put()` runs. The documented "losing an index row only drives cleanup" no longer applies to anything.

## [1.9.4] - September 2026

### Fixed

- **Consumer costs keep their cents on a money consumer.** `Consumer::resolve_cost()` cast the cost to an int, so a 2.50 listing fee was held and charged as 2.00. On a money consumer the cost is now an amount of money rounded to the currency's decimals. Price changes (`reprice_item()`) and the affordability check are compared in minor units, so 2.40 to 2.50 charges exactly 0.10. `record()` / `set_state()` accept and return decimals; whole amounts stay ints, as before. Token consumers are unchanged.

## [1.9.3] - September 2026

### Fixed

- **A site with an older SDK copy loaded first no longer fatals.** The class map is defined by whichever copy is included first (alphabetical plugin order), while classes load from the newest copy. With WB Listora (SDK 1.7.2) and WP Career Board Pro (SDK 1.9.0) both active, 1.9.0's `Registry` asked for `Wbcom\Credits\Expiry`, which 1.7.2's map doesn't list, and every request returned a 500. Each copy now announces its own class map with its version, and one autoloader, registered ahead of any older one, serves every class from the winning copy's map. The fix protects any site where the newest copy is 1.9.3 or later, whatever older copies load first. `tests/loader-election-check.php` reproduces the case with the real v1.8.1 bootstrap loaded first.

## [1.9.2] - September 2026

Fixes from the first independent review of 1.9.1 (Wbcom Credits SDK board), done before any consumer ships 1.9.x. Every item was verified in code before it was fixed.

### Fixed

- **SDK events fire after the write commits.** `wbcom_credits_topped_up` and every other SDK action ran while a claim and its credit were still uncommitted, so a listener could email "funds added" for a purchase that then rolled back, or break the transaction by opening its own. Actions now run after the outermost SDK transaction commits (immediately when none is open) and are dropped on rollback (`Ledger::after_commit()`).
- **A lock taken inside a transaction is held until it ends.** The per-user lock was released when its callback returned, before the enclosing transaction committed, so the next request could read values the first had not committed yet (a refunded amount, a coupon's uses). Named locks taken in an SDK transaction are now released at commit or rollback.
- **Gateway refunds are atomic.** The unspent-balance cap, the revoke and the refund log are read and written under the buyer's lock in one transaction; the checkout row is re-read under the lock. Two refunds for one charge, or a refund racing a spend, could both read the same balance and refunded amount. A failed log write used to leave credits revoked with no record (the result was not checked); it now rolls the revoke and the event claim back, so the provider's retry applies it once. `Transaction_Log::add_refunded_amount()` returns bool.
- **Coupon usage limits can't be oversold.** Usage was counted only from paid orders, so every buyer who started checkout before the first paid could take the last use, and parallel 100% coupon checkouts all passed. The limit is now re-checked and the use recorded under a per-coupon lock: a free order is recorded before the lock is released, and an unpaid checkout holds its use for an hour (filter `wbcom_credits_coupon_hold_seconds`). A buyer who pays after that is still credited.
- **`settle_hold()` refuses to spend more than was held.** The extra skipped the balance check the hold stood for. Price rises hold the difference first (`Consumer::reprice_item()`).

### Added

- **`Credits::credit( $slug, $user_id, $amount, $item_id, $note, $reason = 'refund', $reference )`** - give credits back for an item without it looking like a purchase. Writes an item-linked `topup` row and fires `wbcom_credits_credited`, not `wbcom_credits_topped_up`. Consumers wrote `Ledger::insert()` directly for this.
- **The ledger row id is the 5th argument of `wbcom_credits_topped_up`.** Listeners no longer re-find the row by user, amount and note.
- **`Credits::sum_ledger_grouped( $slug, $args, $group_by )`** (totals and counts per reason, user, entry type or item; `user_ids` filters many users in one query) and **`Credits::get_ledger_row( $slug, $id )`**.
- **`Credits::sdk_ready( $methods )`** - one check for "does the loaded copy have what I call".
- `Ledger::with_lock()`, `Ledger::after_commit()`, `Ledger::get_row()`; `Pending_Checkouts::coupon_holds()`; pending checkouts record `created_at`.

### Changed

- The unused `admin_settings_hook` registration setting is gone from the defaults and docs (nothing ever read it). Docs no longer name templates that don't exist (a balance widget, admin tabs).

## [1.9.1] - September 2026

Found while bundling 1.9.0 into WB Ad Manager Pro, which charges inside its own database transactions (memberships, featured listings).

### Fixed

- **A balance read inside the user's lock is live and locking.** `Credits::get_balance()` under `with_user_lock()` (so in `try_hold()`, `spend()`, `Consumer::reserve_item()` and `reprice_item()`) skips the request cache and reads with `FOR UPDATE`. The named lock is released when the SDK call returns, but a caller's own transaction commits later; a plain read in the next request did not see that uncommitted charge and could approve a second spend of the same money. The locking read waits for it.

### Added

- **`Credits::topup()` takes an `$item_id`** (last parameter), for credits that belong to an item, such as a refunded ad.
- **`Ledger::in_user_lock()`** says whether this request holds the user's lock.

## [1.9.0] - September 2026

Two lines of work in one release. Spends are serialised per user, consumers can drive an item's charge directly, and checkout gains billing, coupons, tax, receipts, expiry and reconciling (found on WP Career Board Pro: ten parallel posts with credit for one made two jobs and a negative balance; auto-published, resubmitted and re-boarded jobs were free; every rejection refunded again). And the ledger design gaps behind the repeated consumer fixes are closed (`docs/AUDIT-2026-09-27.md`, found on WB Ad Manager Pro): holds are settled by id, every row says what happened, claims and credits land together, and reports read through the API. Additive except where noted under Changed.

### Added

- **`Credits::with_user_lock( $slug, $user_id, $fn )`** - a MySQL named lock per (ledger table, user) around "read balance, write". The balance cache is dropped on entry; nested calls for the same user run straight away; the wait is 10 seconds (filter `wbcom_credits_lock_timeout`), after which nothing is written.
- **`Credits::try_hold()` and `Credits::spend()`** check the balance and write under that lock: a hold with an approval step, or a charge per event (a click, a renewal). Before, `hold()` inserted without looking, so every consumer wrote its own check.
- **`Credits::settle_hold()` / `Credits::release_hold()`** act on one hold by the id `hold()` returned; the rows they write carry it (`hold_id`), so a hold is open exactly while nothing points at it.
- **Every ledger row says what happened.** New `reason` (`purchase`, `topup`, `hold`, `hold_release`, `spend`, `refund`, `gateway_refund`, `admin_adjust`, `expiry`) and `reference` (order / session / event / lot) columns, written by every SDK path. A hold release used to be a `refund` row and a gateway refund a `deduction`, so no report could be built from the ledger. `topup()` / `topup_money()` take `$reason` / `$reference` after `$expires_at`; `adjust()` / `adjust_money()` after `$note`.
- **`Credits::query_ledger()`, `count_ledger_rows()`, `sum_ledger()`** filter by user, item, reason, reference, hold and a UTC date range, with paging, so consumers stop querying the table.
- **`Credits::topup_once()`** claims a payment event and credits it in one transaction; every adapter uses it.
- **`Ledger::begin()` / `commit()` / `rollback()`** join an SDK transaction already open instead of committing it (MySQL has no nested transactions).
- **`amount_money` on `POST /topup`** for money consumers (major units, signed); the response states its `unit`.
- **`Consumer::reserve_item()` / `settle_item()` / `release_item()` / `reprice_item()`**, public and returning what happened. `reserve_item()` runs under the lock and refuses what the author can't afford; a released item is charged again; a free item records a zero hold so a later move to a paid tier charges the full difference. `reprice_item()` holds, settles or refunds the difference when an item's price changes. `record()`, `set_state()` and `meta_key()` are public so a consumer can seed records for items charged before they existed.
- **`Registry::consumer( $slug, $id )`** returns the Consumer object for a registered id.
- **`wbcom_credits_adjusted`** action from `Credits::adjust()`.
- **Ledger schema v6 reaches existing sites.** `Ledger::maybe_create_table()` returned as soon as the table existed, so no ledger column or index added since 1.0.0 had reached an upgraded site. `Ledger::maybe_upgrade()` now adds `expires_at`, `reason`, `reference`, `hold_id` and the `idx_item_id`, `idx_user_item_type`, `idx_expiry`, `idx_user_created`, `idx_hold`, `idx_reason` keys when missing.

- **Checkout: billing, coupons, tax, receipts.** `Billing` keeps the buyer's identity on the user under WooCommerce's `billing_*` keys (plus `billing_gst`), basic or full per slug; the checkout route saves what was typed and refuses an incomplete identity (`400 billing_incomplete` with `fields`). `Gateways\Order::build()` is the one money computation (pack price, coupon, tax, total); the gateway sees only the total and the parts are recorded on the Transaction_Log row (`subtotal_cents`, `discount_cents`, `tax_cents`, `coupon`, `billing` JSON snapshot). `Gateways\Coupons` (percent or amount off, expiry, usage limit counted from paid orders) and `Gateways\Checkout_Settings` (billing mode, tax rate and label, seller name/address/tax id, receipt prefix) each ship an admin renderer and sanitizer. A coupon that covers the whole price credits without a gateway. `Receipt` gives every paid order a printable page (buyer and admins only; theme-overridable template) and the data for a receipt email; `wbcom_credits_purchase_completed` fires once per paid order.
- **`Gateways\Fulfilment::credit()`** is the one place an order becomes credits, used by webhooks, return claims, the sweep and free orders.
- **Credit expiry.** A pack can set "credits expire after N days"; top-ups carry `expires_at` (`Credits::topup()` / `topup_money()` take it too) and an hourly sweep (`wbcom_credits_expire_lots`) writes one `expiry` row per lapsed lot for what is left of it (oldest spent first). `wbcom_credits_expired` fires.
- **Reconcile sweep** (`wbcom_credits_reconcile_checkouts`, hourly) claims pending checkouts at their gateway, so a buyer who closed the tab before returning is still credited; pending entries live 7 days.
- **`Credits::mapped_offers()`** lists the mapped store items a member can buy, with where to buy each (filter `wbcom_credits_offer_url`).
- **`Support\Currencies` / `Support\Countries`**: complete ISO 4217 (with real minor units) and ISO 3166 lists, one source for every product (the countries list defers to WooCommerce when active).

### Changed

- **`wbcom_credits_low` fires once per crossing** (user meta flag, cleared when the balance goes back above the threshold) and on every debit path (hold, deduct, adjust). It fired on every hold at or below the threshold, so a member posting several items got an email per post. On a money consumer the threshold is money (the default 5 means 5.00, not 5 cents).
- **`Credits::deduct()` settles an open hold or returns false.** With no open hold it wrote a release and a deduction that cancelled out: the member paid nothing and the call reported success.
- **`cancel_hold()` and `cancel_hold_by_id()` only cancel an open hold.** `cancel_hold( $item_id )` deleted every hold on the item, including one already settled, which silently reversed that charge.
- **`Credits::refund()` releases the item's open hold (its own amount) when there is one**, otherwise it credits the amount back as a `refund`. The `wbcom_credits_refunded` event is unchanged.
- **`Consumer` settles and releases the item's holds by id**, each for what it holds; repricing a held item down releases it and holds the new price.
- **Gateway claims and credits are one transaction** (webhook, return claim, `Fulfilment::credit()`). A fatal error or timeout between them kept the claim and lost the credit, and the provider's retry was then a duplicate; a failed event now rolls its claim back.
- **Gateway refunds on money consumers are prorated in cents.** The share was floored in whole units: a third of a 10.00 purchase revoked 3.00, not 3.33.
- `bin/audit.sh` compares public API symbol names, so a moved line or a new optional parameter is no longer reported as a breaking removal.
- **`POST /topup` takes a signed amount.** `absint` turned -3 into +3.

- **`Money` reads decimals from the currency registry**; its partial zero/three-decimal lists are gone. The pack editor's currency is a select from the registry, prices are stored in the currency's own minor units, and the custom-amount rate is entered as a price per credit.
- **`checkout.js`** sends billing and a coupon, reports missing billing fields, and `wbcomCreditsClaim()` credits a paid checkout on return.
- **A gateway that can't start a checkout** answers the buyer with a plain message; the provider's detail goes to the debug log.

### Fixed

- **Zero- and three-decimal currencies.** Pack prices were stored and PayPal amounts sent as `price * 100` / `/ 100`: a JPY 500 pack charged ¥50,000, KWD lost its third decimal.
- **Delayed Stripe payments** (`checkout.session.async_payment_succeeded`) were never credited.
- **A paid checkout on a site without a webhook** was never credited: nothing called the claim route on return.
- 1.8.1's changelog said rows were stamped with `current_time( 'mysql', true )`; they are stamped with `gmdate( 'Y-m-d H:i:s' )` (the same UTC value), so the SDK also runs without WordPress loaded.
- `tests/loader-election-check.php` rewrote a literal `'1.7.1'` that stopped existing at 1.8.0, so both fake copies announced the same version and the check failed.

## [1.8.1] - September 2026

### Fixed

- **Every SDK row is stamped in UTC.** `Ledger::insert()`, `Transaction_Log::insert_checkout()` / `insert_refund()` and `Processed_Events` left `created_at` to the column's `DEFAULT CURRENT_TIMESTAMP`, which MySQL fills in the server's time zone. On a database set to anything but UTC (+05:30, say) every balance entry was hours off, while consumers write their own tables in UTC and show dates in the site zone. Each writer now passes `current_time( 'mysql', true )`. Rows written before this release keep their old stamps; a consumer that converts its tables to UTC should include `{prefix}_credit_ledger`, `{prefix}_credit_gateway_log` and `{prefix}_credit_processed_events` as server-clock columns. Found by WB Ad Manager QA.
- The WooCommerce adapter's refund docblock matches the 1.8.0 policy (the balance never goes negative).

## [1.8.0] - September 2026

First tagged release since 1.7.0. Consolidates the 1.7.0 (copy election), 1.7.1 (PayPal capture on return) and 1.7.2 (refund and checkout integrity) development cycles - none of which were tagged or released on their own - plus three additions made while preparing this release: `Pending_Checkouts::for_user()` upstreamed from a downstream fork, a refund policy that caps every gateway refund at the buyer's unspent balance, and a flattened admin layout for the Credits settings templates.

### Added

- **`Pending_Checkouts::for_user()`.** The store only supported lookup by session id, so a consuming plugin had no way to show "awaiting payment" for a buyer's own direct-gateway checkout in its wallet UI (the equivalent view already existed for adapter purchases via `Transaction_Log`). Returns every non-expired pending checkout for one user, newest first. Originated in WB Ad Manager Pro's bundled copy while building its wallet pending-state display; upstreamed here so every consumer gets it, and re-implemented against the per-session storage introduced by 1.7.2 (the fork's version read a shared-option store that no longer exists).
- **`Consumer` is money-mode aware (#7 follow-up).** A money consumer's ledger holds integer MINOR units, but `Consumer` compared and charged in whatever unit `resolve_cost()` returned - so a 10-credit listing fee reserved 10 *minor* units, roughly a 1/100th charge on a hundredths-based currency, silently. It now dispatches through `balance_money()` / `hold_money()` / `deduct_money()` / `refund_money()` when the consumer registers `money`, and through the raw methods otherwise. Token consumers are unaffected. Ported from a downstream fork that had carried this fix privately.
- **`Credits::cancel_hold_by_id()` and `Ledger::cancel_hold_by_id()`.** `cancel_hold()` deletes every hold on an item, so a consumer that placed two holds and wanted to drop one released both. These cancel a single hold by the row id `hold()` / `hold_money()` returned. Also ported from the same fork, where it was already in production use.
- **`Credits::resolve_money_currency()` is now public.** It was private, so a consumer rendering a stored ledger figure had no supported way to ask which currency governs the conversion and had to guess, or hardcode `/100`, which is wrong for JPY and other zero-decimal currencies.
- **`Credits::purchase_paths()` / `Credits::can_purchase()` - the SDK now owns "can a member buy credits here?" (#7).** The SDK already owned every fact needed to answer it (`Gateway_Registry::get_available()`, `AdapterRegistry` and the `{slug}_credit_mappings` option it reads, `get_purchase_url()`) but exposed no composite, so each consumer assembled its own from whichever primitives it happened to need. They drifted: in one consumer three separate answers existed, one counting adapter mappings but not gateways, another gateways but not mappings, and the narrowest was the one gating member-facing UI - so a site selling credits through a mapped WooCommerce product hid the Credits UI from members who could genuinely buy. `purchase_paths()` returns the live routes (`gateway`, `mapping`, `external_url`) rather than a bare boolean, because a consumer telling an owner what to fix must distinguish "no gateway" from "no mapping". A mapping counts only when its adapter reports `is_available()`, which also closes a second-order bug: consumers were hard-coding per-adapter availability checks and had missed `woo_memberships` entirely, so any adapter the SDK gains was invisible until someone edited a list in another repository. Consumers contribute their own routes (their own credit-pack products, say) via the `wbcom_credits_purchase_paths` filter. Additive; no existing method changes behaviour.
- **`wbcom_credits_checkout_enabled` filter** (default `Credits::is_enabled()`), checked before a checkout is started. The checkout route is registered unconditionally, so a consumer that had switched credits off still sold them. Completing or claiming a payment already made, and refunds, are not gated. Separate from `wbcom_credits_enabled`, which also drives the balance API's `enabled` flag.
- **Selling switched off closes every purchase path.** `Credits::checkout_enabled()` is the one gate (it applies `wbcom_credits_checkout_enabled`). The WooCommerce adapter makes mapped credit products (and their variations, and WooCommerce Subscriptions mappings) unpurchasable and says so on the product page; WooCommerce then drops one already in the cart at checkout. The MemberPress adapter refuses a credit-granting membership (`mepr-can-you-buy-me-override`), and the PMPro adapter stops checkout of a credit-granting level (`pmpro_registration_checks`). `can_purchase()` is false while off; `purchase_paths()` still lists what is configured. Orders paid before are still credited. Found on WB Listora: with Monetization off a mapped WooCommerce product still took payment and granted credits the member could neither see nor spend.
- **`Consumer` records each item's hold (held / settled / released, with the amount held).** Settle and release now act only on an open hold, for the amount held. A second hold event no longer reserves again, a republish (renewal, reactivation) no longer writes a zero-sum release + deduction, and deactivating or trashing an item whose credits were already settled no longer refunds them - on WB Listora a member could take a paid listing down, get the submission cost back and put it up again free. Items held before this release carry no record and behave as before.
- **`Credits::invalidate_cache()` is public**, for consumers that serialise spends with their own lock: a balance read earlier in the request is cached, so after taking the lock the next read must come from the ledger. Reproduced on WB Listora: two submissions at once with credits for one, both charged.
- **`Credits::count_ledger()`** - a user's ledger row count, to page `get_ledger()`. `Ledger::get_history()` now orders by `created_at DESC, id DESC`, so rows written in the same second (a hold, its release and its deduction) no longer shift between pages.
- **`can_buy` on `GET /wbcom-credits/v1/{slug}/balance`** - whether to show a buy button. `enabled` keeps meaning "credits exist here", since balances stay readable while selling is off.
- **Flattened admin layout for the Credits settings templates.** `templates/admin/gateways-section.php` wrapped each gateway in its own `<section>`, and `Pack_Admin_Renderer` wrapped its fields in a `<fieldset>` (which browsers border by default) with each field as its own `<p class="...-field">`. A consumer that renders these inside its own settings-page card ended up with boxes nested inside boxes. Both now emit ONE outer wrapper holding sub-headings and `.form-table` rows directly - never a card inside a card inside a card. Every field name, id, nonce and save behaviour is unchanged; the SDK still ships no CSS of its own, so dark mode and RTL keep coming from whatever tokens the host page already applies. Also drops a redundant "Enable X" caption next to a gateway's enable checkbox (the row's own `<th><label>` already names it, and the caption read as contradictory beside an "Off" status) - WB Ad Manager Pro had already made the identical fix in its own bespoke settings markup; this brings the SDK's default template in line.

### Fixed

- **Gateway refunds only take back the buyer's unspent balance.** The credit count to revoke was prorated from the ORIGINAL purchase alone (`floor( orig_credits * refund_amount / orig_amount )`) and applied with no reference to the current balance, so a full refund on a purchase the buyer had mostly spent could drive the ledger negative. Every gateway refund now caps at `min( prorated_share, current_balance )`; a fully-spent purchase revokes nothing. The WooCommerce adapter's order refunds and cancellations follow the same cap, and an order whose refund was capped counts as settled, so a later refund event on it never takes credits bought since. This is the SDK-wide default, the same for every consumer: credits are a prepaid service, so a refund cannot claw back value already delivered (a listing published, an ad shown). The refund action hooks (`wbcom_credits_gateway_refund`, `wbcom_credits_refunded`) keep their existing signature and now report the amount ACTUALLY taken back rather than the requested or prorated amount, so a consumer bridging revenue (e.g. WB Ad Manager Pro's `Credits_Bridge::record_gateway_refund()`, which re-reads the ledger row and needed no change) reads a number that reconciles with the ledger. See the new "Refund policy" section in the README. The SDK does not pause anything and adds no refund UI beyond the existing admin-initiated `POST /refund/{gateway}` route; a consumer needing richer handling builds it on the existing hooks.
- **WooCommerce refunds and cancellations remove the credits an order granted.** Nothing listened for them: a fully refunded credit order left the buyer with every credit. `WooCommerceAdapter` now handles `woocommerce_order_refunded` (full and partial; each refund revokes the order's refunded share minus what is already revoked, claimed once per refund id) and `woocommerce_order_status_cancelled` (the rest of the grant). What an order granted is stored on it (`_wbcom_credits_granted_{slug}`); orders credited before this release fall back to the current mapping. Each revocation fires `wbcom_credits_refunded` with reason `gateway_refund`, gateway `woocommerce`.
- **Stripe refunds find their checkout.** Checkouts sent `payment_intent_data[metadata][wbcom_session] = {CHECKOUT_SESSION_ID}`, but Stripe fills that placeholder only in `success_url`, so every charge carried the literal text; being non-empty it stopped the payment-intent fallback and every refund was dropped as `refund_for_unknown_checkout`. The stamp is removed and the literal is treated as absent, so refunds resolve through the recorded payment intent, including for charges made before this release.
- **Partial Stripe refunds no longer over-revoke.** `charge.refunded` carries the cumulative `amount_refunded`; it was treated as the new refund, so $3 + $3 on a $10 charge revoked $9 worth of credits. `Gateway_Event` has a new `amount_is_cumulative` flag (Stripe sets it) and `process_refund()` applies only the part not yet refunded. PayPal is unchanged.
- **A paid checkout is no longer lost when two members check out at once.** `Pending_Checkouts` kept every session in one option that each `put()` / `forget()` read and rewrote, so concurrent checkouts could drop each other's entry and that buyer's webhook and return claim 404'd. Each session is now its own option; entries in the pre-1.7.2 shared option are still read and removed; abandoned entries are swept in bounded batches on `put()`.
- **A failed crediting attempt no longer burns its claim.** The event claim (and the session claim on a top-up failure) stayed taken when crediting failed, so the provider's retry was acked as a duplicate and the session was never credited. Both are released on failure (`Idempotency::release()`, `Processed_Events::release()`).
- **`wbcom_credits_refunded` from a gateway refund carries ledger units.** Arg 3 is documented as the ledger amount and `Credits::refund()` sends minor units for a money consumer, but gateway refunds sent the credit count, so a money consumer read a 100-credit refund as 1.
- **PayPal purchases were never captured, so buyers were never charged and never credited.** Orders are created with `intent: CAPTURE`, and an approved PayPal order takes no money until `POST /v2/checkout/orders/{id}/capture` is called. Nothing called it: the redirect claim inherited the base `retrieve_checkout_event()` (always null, so `202 pending`) and the only crediting path was the `PAYMENT.CAPTURE.COMPLETED` webhook, which PayPal sends only after a capture. `PayPal::retrieve_checkout_event()` now reads the order returned as `token`, captures it when `APPROVED` (idempotent via `PayPal-Request-Id: capture-{order}`), and reports only a `COMPLETED` capture; a `PENDING` capture stays uncredited. A `CHECKOUT.ORDER.APPROVED` webhook performs the same capture, so a buyer who closes the tab after approving is still charged and credited. The existing session-scoped claim keeps the redirect claim, the approved webhook and the later capture webhook to exactly one credit. Found by WB Listora QA.
- **`PAYMENT.CAPTURE.COMPLETED` without `supplementary_data` used the raw `custom_id` JSON as the session id**, so the webhook matched no checkout. It now reads the stamped order id out of `custom_id`, the same way the refund path does.

### Tests

- `tests/Gateways/PendingCheckoutsTest.php` extended - `for_user()` returns only the requesting user's non-expired entries, excludes expired ones, and reads pre-1.7.2 legacy shared-option entries.
- `tests/Gateways/GatewayRefundEventTest.php` and `tests/Gateways/GatewayMoneyModeTest.php` extended - a refund larger than the unspent balance reverses only the balance and never goes negative (token and money consumers), a repeated webhook after the cap changes nothing, and the refund hook receives the amount actually taken back.
- `tests/Credits/ConsumerMoneyModeTest.php` (new) - locks the money/token dispatch: a money balance reads back in major units, a major-unit hold reserves minor units, hold to commit charges exactly once, a token consumer keeps integer semantics, and `cancel_hold_by_id()` removes one hold while leaving a sibling hold on the same item intact.
- `tests/Credits/CreditsPurchasePathsTest.php` (new) - locks: a bare site has no route and `can_purchase()` stays false, a same-site purchase URL is not a route on its own while an off-site one is, a mapping to an unavailable adapter does not count, consumer-contributed routes count, and every route is returned boolean-cast.
- `tests/Gateways/RefundAndCheckoutIntegrityTest.php` (new): cumulative refunds, claim release and retry, no placeholder metadata, placeholder charges resolving by payment intent, legacy pending entries, per-session entries.
- `tests/Adapters/WooCommercePaymentGuardTest.php`: full, partial, repeated and cancelled-order revocation; uncredited orders revoke nothing.
- `tests/Gateways/PayPalCaptureClaimTest.php` (new) - approved order captured and credited with the idempotency header; unapproved order not captured; already-captured order credited without a second capture; pending capture not credited; approved webhook captures for a buyer who never returned; claim + capture webhook credit exactly once; capture webhook resolves the order from a stamped `custom_id`.

## [1.6.0] - 2026-08-04

### Added

- **Synchronous redirect claim — credits land without a webhook.** Before 1.6.0 the ONLY crediting path was the provider webhook, so a site whose owner never configured one (or that the provider cannot reach — local, staging, firewalled) captured real payments and granted nothing (found live as WB Ad Manager Basecamp card 10134503233). New `POST /{slug}/claim/{gateway}` (authenticated) verifies the returned `session_id` against the provider server-side and credits it: `Stripe::retrieve_checkout_event()` retrieves the Checkout Session with the SECRET key and only reports paid sessions; amount and currency are then cross-checked against `Pending_Checkouts` exactly like a webhook delivery — nothing from the browser is trusted but the session id. Buyers may only claim their own sessions (admins any); a claim for an already-credited session answers `{already: true}` from the `Transaction_Log` instead of an error. Gateways that cannot verify synchronously (PayPal, custom) inherit a default that answers `202 {pending: true}` and stay webhook-only.
- **Session-scoped idempotency shared by both crediting paths.** The provider-event-id claim in `handle_webhook()` cannot serialize a webhook racing a redirect claim — they carry different ids for the same payment — so `process_checkout_completed()` now atomically claims `session:{id}` before any ledger write. Exactly one path credits; the loser acks as a duplicate. Locked by `CheckoutClaimTest::test_racing_webhook_and_claim_credit_exactly_once()`.
- **Consumer-registered checkout return URL.** Every plugin using the SDK has its own wallet/dashboard where buyers top up — landing them on the site home after payment was always wrong. `Registry::register()` now accepts `return_url` (string or callable, resolved at checkout time): when a checkout request carries no explicit `return_url`, the consumer's registered page is used before the gateway settings/home fallbacks.
- **`Credits::forget_balance()` — public per-request balance-cache invalidation.** Consumers that write ledger rows directly via `Ledger::insert()` (bridges with their own charge/refund semantics, like WB Ad Manager Pro's Credits_Bridge) left `get_balance()` stale for the rest of the request, because only the Credits API invalidated the cache. Such consumers should call `forget_balance()` after every direct write.

- **Settings fields now declare `required` explicitly.** Consumers rendering gateway status badges guessed requiredness from field types ("every password field is required"), which marked Stripe "Incomplete" when the optional webhook signing secret was empty (WB Ad Manager Basecamp card 10143130518). Stripe's `publishable_key`/`secret_key` are `required => true`; `webhook_secret` is `required => false` — with the redirect claim, payments complete without it (it adds refund sync and a crediting fallback). PayPal's `client_id`/`client_secret`/`webhook_id` are all required since PayPal remains webhook-only.

### Fixed

- **Fallback success/cancel URLs now carry the gateway marker.** The `wbcom_credits`/`gateway`/`credits` params were appended only when the caller passed a `return_url`; a consumer relying on the settings or home_url fallback got a success URL with no gateway marker, so its return handler could not tell which gateway to claim the session against (flagged by WB Ad Manager QA as the unfixed seam behind card 10134503233 — the plugin had fixed it at the caller only). Both Stripe and PayPal now append the params to whichever base wins. Locked by `CheckoutReturnUrlTest`.
- **Gateway purchases on money consumers credited 1/minor-factor of what the buyer paid for.** 1.5.1 fixed adapter mappings feeding major-unit values into the integer ledger API, but missed the gateway orchestrator: `process_checkout_completed()` fed the purchased credit count straight into `Credits::topup()`, so a $10 purchase of 100 credits on a money consumer landed as 1 credit (found live on WB Ad Manager Pro while verifying the redirect claim). The refund path had the mirror bug via `Credits::adjust()`. Both now route through `topup_money()`/`adjust_money()` when the consumer registered `money`; token consumers are byte-for-byte unchanged. Locked by `GatewayMoneyModeTest`.
- **Version self-registration was stale.** The bootstrap registered itself with `Versions` as `1.4.2` while defining `WBCOM_CREDITS_SDK_VERSION` as `1.5.1`, so in a multi-bundle election this copy would lose to any copy registering >= 1.5.0 despite being newer. Registration string, initializer function names, and the constant now all carry the real version.

## [1.5.1] - 2026-07-28

### Fixed

- **Adapter and gateway credit mappings now convert to ledger units on money consumers.** 1.5.0 moved money consumers to `topup_money()`/`deduct_money()` and removed the per-consumer `ledger_scale` that used to scale amounts inside `Credits::topup()`. But the bundled adapters (WooCommerce, WooCommerce Subscriptions, WooCommerce Memberships, PMPro, MemberPress) and both gateways feed `AdapterRegistry::lookup_credits()` straight into the integer `Credits::topup()`, which writes the ledger's minor units verbatim — so an admin mapping a product to `100` credited **$1.00 instead of $100.00** on a money consumer. Mapping values are authored in a settings screen and are major units by definition, so `lookup_credits()` now converts them through `Money` when the consumer registered `money`. Converting there rather than in each adapter means a new adapter cannot reintroduce it — the same single-boundary rule the money-mode API follows. Token consumers (no `money` config) are returned the integer unchanged and are unaffected. No schema change, no data migration: stored mappings stay in major units, which is what the admin typed.

## [1.5.0] - 2026-07-28

### Added

- **First-class "money mode" so money-denominated consumers can't mix major/minor units (#3).** `Money` (1.5.0) gave consumers a correct converter, but using it was opt-in at every entry point — admin add, the consumer's webhook, the payment adapters — and missing any one silently mixes minor and major units, corrupting a balance (found in a live consumer: `(int) 0.5` stored 0 credits on a "success"). A consumer now declares its ledger is money once — `'money' => array( 'currency' => 'USD' )` (an ISO code or a callable) — and uses the new convenience API that converts MAJOR-unit amounts to the ledger's integer MINOR units through `Money` at a single enforced boundary: `Credits::topup_money()`, `hold_money()`, `deduct_money()`, `refund_money()`, `adjust_money()`, and `balance_money()` (reads back as a major-unit float), plus `Credits::is_money()`. Currency resolves from the call argument, else the consumer's `money.currency`, else USD. Token consumers that register no `money` key are unaffected — the integer `topup()/deduct()/refund()` behave exactly as before. Ledger stays `amount INT`. Additive; no schema change.

### Tests

- `tests/Credits/CreditsMoneyModeTest.php` (new) — locks: sub-unit top-ups are not lost (0.5 → 50 minor, the truncation bug), USD 147.35 → 14735 round-trips, zero-decimal (JPY, no ×100) and three-decimal (KWD, via a callable currency) conversion, the hold→deduct→refund money lifecycle, signed `adjust_money`, and `is_money()` gating.

## [1.4.2] - 2026-07-13

### Added

- **`Transaction_Log::list_transactions()` + `count_transactions()` — the read side of the gateway log.** The append-only gateway log had writers (checkout/refund inserts) and single-row lookups but no way for a consumer to LIST it. These add a paginated, newest-first reader (filterable by `kind`, `gateway`, `user_id`; `limit` clamped 1..100 + `offset`) and a matching count for pagination totals, so a consuming plugin can surface an admin "Transactions" view — every purchase and refund with its money amount, credits, gateway, and the `session_id` the refund route needs. Read-only; no schema change.

### Tests

- `tests/Gateways/TransactionLogReaderTest.php` (new) — locks newest-first ordering, limit/offset pagination, `kind`/`gateway`/`user_id` filtering, and cross-slug isolation (one consumer never sees another's rows through the shared table).

## [1.4.1] - 2026-07-13

### Fixed

- **[HIGH] PMPro + MemberPress adapter idempotency is now atomic (parity with WooCommerce).** `PMProAdapter::on_level_change()` and `on_subscription_payment()` deduped with a read-then-write user-meta flag (`get_user_meta()` → `topup()` → `update_user_meta()`); `MemberPressAdapter::on_transaction_completed()` deduped with a `note LIKE '%...%'` scan of the SDK ledger table. Both are read-modify-write guards with a TOCTOU window: two concurrent deliveries of the same event (a retried PMPro level-change hook, or a replayed MemberPress transaction event) could both read "not processed" before either saved, and both top up. All three adapter methods now route dedupe through the same atomic `Gateways\Processed_Events::claim()` (UNIQUE `INSERT IGNORE`) that the WooCommerce adapters and the gateway webhook path already use — claiming FIRST, before the credits lookup, under a stable per-event id (`pmpro:level:{user}:{level}:{date}`, `pmpro:order:{order_id}`, `mepr:txn:{txn_id}`) tagged `adapter:{id}`. PMPro's legacy meta flag is retained as a human-readable support/reconciliation marker but is no longer the guard. MemberPress's non-atomic `is_already_processed()` ledger scan is removed (dead code once the atomic claim replaces it). The processed-events table is already created at boot for every consumer, so no schema change is needed.

### Added

- **Gateway parity matrix test (`tests/Gateways/GatewayParityMatrixTest.php`).** Drives the real `Stripe` and `PayPal` gateway classes through equivalent checkout-completed and refund events via the shared `Abstract_Gateway::handle_webhook()` orchestration, and asserts the domain outcome (credits topped up / revoked, `Transaction_Log` row shape, `wbcom_credits_refunded` payload) is identical for both providers. Locks the guarantee that `normalize_event()` is the only place Stripe and PayPal are allowed to diverge — no divergence was found in the shared path.

### Tests

- `tests/Adapters/AdapterIdempotencyTest.php` extended with concurrent-delivery cases for PMPro `on_level_change`, PMPro `on_subscription_payment`, and MemberPress `on_transaction_completed` — for each, N racing deliveries of the same event credit the user exactly once.
- `tests/Gateways/GatewayParityMatrixTest.php` (new) — see Added above.

## [1.4.0] - 2026-07-13

### Added (frontend checkout)

- **Reusable JS checkout helper (`assets/js/checkout.js`).** Registers a `window.wbcomCreditsCheckout({ slug, gateway, pack_id, credits, returnUrl })` global that POSTs to `/{slug}/checkout/{gateway}` (with `X-WP-Nonce`), then redirects the browser to the hosted checkout URL the SDK returns. `Registry` registers (not enqueues) a `wbcom-credits-checkout` script handle localized with `wbcomCreditsCfg = { restRoot, nonce }`, once per request regardless of consumer count; consuming plugins call `wp_enqueue_script('wbcom-credits-checkout')` where they render a buy button. This is the browser half of the existing `/checkout/{gateway}` REST route — no consumer has to hand-roll the fetch/redirect.
- **Reusable admin pack-editor (`Gateways\Pack_Admin_Renderer`).** `render( $option_name )` echoes an escaped, dependency-free fieldset for credit packs ({credits, price}) plus a custom-amount group (enabled / per-credit rate / min / max) and currency; `sanitize()` (hand it to `register_setting()`) normalizes the POST into the exact `pricing`-shaped array `Pricing::resolve()` consumes, dropping rows with non-positive credits or price. Consuming plugins get the packs + custom-amount admin UI without rebuilding it.
- See `docs/CONSUMER_FRONTEND_CHECKOUT.md` for the end-to-end wiring recipe.

### Fixed (money-path)

- **[CRITICAL] Atomic webhook idempotency.** `Gateways\Idempotency` used an option-backed FIFO ring with a read-modify-write (`get_option` → `in_array` → `update_option`). Two concurrent deliveries of the same provider event could both pass `is_processed()` and both credit the user. Idempotency now uses a dedicated `{prefix}_credit_processed_events` table with a `UNIQUE (slug, gateway, event_id)` key (new `Gateways\Processed_Events` class). `mark_processed()` performs a single `INSERT IGNORE` and returns `true` only when a row was newly inserted (`rows_affected === 1`) — so exactly one of N racing deliveries wins the claim and the rest are rejected. `Abstract_Gateway::handle_webhook()` now **claims the event atomically BEFORE any ledger write** (claim-then-act); the post-credit `mark_processed()` calls were removed. `is_processed()` is retained as a cheap pre-check only. `Idempotency`'s public API (`is_processed`, `mark_processed`, `reset_for_tests`) is unchanged — it is now a thin facade over `Processed_Events`.
- **[HIGH] Adapter idempotency is now atomic (TOCTOU fix).** The bundled membership/subscription adapters deduped with a read-then-write order/membership meta flag (`get_meta('_wbcom_credits_processed')` → `topup()` → `save()`). WooCommerce fires BOTH `order_status_completed` and `_processing`, and any status transition can arrive from concurrent requests (gateway IPN + admin, or two IPNs) — so two deliveries could both read "not processed" before either saved and both top up. `WooCommerce`, `WooSubscriptions`, and `WooMemberships` adapters now route dedupe through the same atomic `Gateways\Processed_Events::claim()` (UNIQUE `INSERT IGNORE`) the webhook path uses, with a stable per-order/per-membership event id under an `adapter:{id}` gateway tag (`woo:order:{id}`, `woosub:order:{id}`, `woomembership:membership:{id}`). The adapter claims FIRST and only credits when it won the claim; the legacy meta flag is retained as a human-readable support marker but is no longer the guard. WooMemberships keeps its original "credit once, ever, per membership" semantics (the membership-id claim row persists across an active→expired→active cycle). The processed-events table is already created at boot for every consumer, so no new schema is needed.
- **[HIGH] Gateway refunds now fire the generic `wbcom_credits_refunded` action — with the revoked amount + linkage context.** A gateway-initiated refund revokes credits via `Credits::adjust(-credits)` (which intentionally fires no SDK action) and logged a `Transaction_Log` refund row, but only fired the gateway-scoped `wbcom_credits_gateway_refund` action — so consumer refund consumers (audit log, outgoing webhooks, notifications) bridged to the documented `wbcom_credits_refunded` contract were silently skipped for every Stripe/PayPal refund. `Abstract_Gateway::process_refund()` now fires `wbcom_credits_refunded( $slug, $user_id, $credits_revoked, $context )` after a successful revoke, only when credits were actually revoked. The richer `wbcom_credits_gateway_refund` action still fires alongside it. **Signature change (additive, but the 3rd arg changed meaning):** the generic `wbcom_credits_refunded` action is now `( $slug, $user_id, $amount, $context )` across BOTH fire sites — the gateway path AND `Credits::refund()` (hold-lifecycle). The 3rd arg is the refunded/revoked **credit amount** (positive int); the 4th `$context` is an assoc array carrying `reason` (`gateway_refund`|`hold_refund`), `item_id`, `ledger_id`, and — for gateway refunds — `gateway`, `session_id`, `provider_ref`. The previous 3rd arg on the hold path was `item_id`, which now lives in `$context['item_id']`. Existing 3-arg listeners keep firing (they receive slug/user_id unchanged); consumers reading the 3rd arg as item_id must move to `$context['item_id']`. This unblocks Pro consumers (audit-log amount attribution, perk reversal) that previously had no way to read the amount or map a gateway refund back to a listing. The public method API surface is unchanged (this is a hook-argument change, not a method-signature change), so `bin/.api-surface.txt` is unaffected.
- **[HIGH] PayPal refund → parent checkout linkage (PayPal analogue of the Stripe fix).** `PayPal::normalize_event()` for `PAYMENT.CAPTURE.REFUNDED` resolved the parent checkout only from `supplementary_data.related_ids.order_id` / `parent_payment`, which PayPal does not reliably include on refund webhooks — so refunds that omitted it never matched the recorded parent (keyed by the PayPal order id) and credits were never revoked; there was no fallback (Stripe already had one). Fixes, mirroring Stripe's prefer-stamp-then-fallback: (1) checkout creation PATCHes the freshly-minted order's purchase-unit `custom_id` to embed the order id (`{ slug, user_id, credits, session }`), which PayPal copies onto the capture and the refund resource — so the refund webhook carries our order id back; the normalizer now **prefers** that stamped `custom_id`. (2) The checkout-completed event now records the PayPal **capture id** as `provider_ref` (stored in `Transaction_Log.payment_intent`), so a refund carrying neither the stamp nor the order id falls back to `Transaction_Log::find_checkout_by_payment_intent()` keyed on the capture id (`related_ids.captured_payment` / `up_id`). The `custom_id` PATCH is best-effort (non-fatal on failure) because the capture-id fallback covers it. Zero/negative refund amounts are now treated as non-events (matches Stripe); the captured-total clamp is unchanged and still enforced in `Abstract_Gateway::process_refund()`.
- **[HIGH] `payment_intent` column now actually lands on EXISTING installs (the linkage fix below depended on it).** `Transaction_Log::maybe_create_table()` early-returned the instant its `SHOW TABLES LIKE` guard found the table present, so the `CREATE TABLE` — the only place the `payment_intent` column + `idx_intent` key were declared — ran on FRESH installs only. On every upgraded site the column was missing and the refund parent-linkage below silently failed exactly where it was meant to be fixed. `maybe_create_table()` now runs `CREATE TABLE` only when the table is absent, then ALWAYS calls a new idempotent private helper `ensure_intent_column()` that probes `SHOW COLUMNS` / `SHOW INDEX` and adds the column/index via explicit `ALTER TABLE` when missing (`VARCHAR(191) NOT NULL DEFAULT ''` to match the fresh schema; index `(slug, gateway, payment_intent)`). It is re-runnable (no-op once present) and gated by the per-consumer `wbcom_credits_db_version_{prefix}` option so a v1→v2 upgrade hits the ALTER exactly once. Explicit `ALTER` is used rather than relying on `dbDelta`: `dbDelta` never ran on the early-return existing-table path and is unreliable at adding non-PRIMARY/UNIQUE keys — for money code the guarded, verifiable path is clearer. Public API surface unchanged.
- **[HIGH] Stripe refund → parent checkout linkage.** `Stripe::normalize_event()` for `charge.refunded` set `session_id = $charge['payment_intent']` (a `pi_…` id) first. But the parent payment row is keyed by the Checkout Session id (`cs_…`), so on the normal path (payment_intent present) the parent lookup never matched and credits were never revoked. Fixes: (1) checkout creation now stamps the session id onto the PaymentIntent via `payment_intent_data[metadata][wbcom_session]`, which Stripe copies onto the charge, so `charge.refunded` carries the `cs_…` id back; the normalizer now **prefers** that metadata. (2) For legacy sessions created before the stamp, the normalizer falls back to a secondary lookup that translates `payment_intent` → recorded `cs_…` via the new `Transaction_Log::find_checkout_by_payment_intent()`. The checkout `Transaction_Log` row now stores `payment_intent` (new column + `idx_intent` key). The amount/partial-refund clamp logic is unchanged.

### Schema

- DB schema version is now **3**, tracked per-consumer in the `wbcom_credits_db_version_{prefix}` option (introduced by this release; previously the SDK had no DB-version option and relied on `SHOW TABLES` probes alone). `Registry::boot_all()` calls a guarded `maybe_upgrade_schema()` that runs the idempotent create/upgrade pass only when the stored version is behind. Changes by version: **v2** added the `{prefix}_credit_processed_events` table (`UNIQUE (slug, gateway, event_id)`) and *intended* to add the `payment_intent` column + `idx_intent` key on `{prefix}_credit_gateway_log` — but the early-return bug meant that column was added on FRESH installs only, leaving sites that booted the v2 build stuck at version 2 WITHOUT the column (the gate thought the work was done). **v3** re-runs the now-fixed idempotent backfill (`Transaction_Log::ensure_intent_column()` — explicit guarded `ALTER TABLE`, NOT `dbDelta`, which is skipped on the existing-table path and unreliable for plain keys) so those corrupted v2 installs get the `payment_intent` column + `idx_intent` index added; it no-ops where they already exist, so the bump is safe for fresh installs too. No back-fill/migration of the old option-ring is performed — the ring was a short-lived dedupe cache (last 1000 event ids), and any event old enough to only exist in the ring is far past every provider's webhook-retry window, so there is nothing to migrate. The table is the single source of truth going forward.

### Tests

- `tests/Gateways/IdempotencyTest.php` rewritten for the atomic model, incl. a concurrency test asserting exactly one of N claims for the same event wins.
- `tests/Gateways/GatewayRefundEventTest.php` (new) — gateway refund fires `wbcom_credits_refunded` once with the documented payload (now asserting the 4-arg `( $slug, $user_id, $amount, $context )` shape: 3rd arg is the revoked amount, 4th carries gateway/session_id/provider_ref/ledger_id/reason), prorated partial refunds, and replay safety (no double-revoke / no re-fire).
- `tests/Adapters/AdapterIdempotencyTest.php` (new) — N concurrent deliveries of the same WooCommerce order (and the processing→completed pair) credit exactly once via the atomic `Processed_Events::claim()`; a different order still credits.
- `tests/Gateways/PayPalRefundLinkageTest.php` (new) — `PAYMENT.CAPTURE.REFUNDED` resolves the parent via the stamped `custom_id` (preferred), via `supplementary_data.related_ids.order_id`, and — when both are absent — via the `Transaction_Log` capture-id fallback; an unresolvable refund returns a null event; checkout normalizer captures the capture id as `provider_ref`.
- `tests/Credits/CreditsRefundEventTest.php` (new) — `Credits::refund()` fires the generic action with the new 4-arg signature (amount as 3rd arg, `item_id`/`ledger_id`/`note`/`reason` in `$context`).
- `tests/Gateways/StripeRefundLinkageTest.php` (new) — `charge.refunded` with `payment_intent` present resolves the parent via the metadata stamp (normal path) and via the `payment_intent` secondary lookup (legacy path); checkout normalizer captures `payment_intent`.
- `tests/Gateways/TransactionLogUpgradeTest.php` (new) — locks the v1→v2 upgrade PATH: a pre-existing SDK-1.2.0 gateway-log table (no `payment_intent`, no `idx_intent`) gains both after `maybe_create_table()`, the `payment_intent` refund lookup resolves the parent afterwards, the upgrade is idempotent on re-run (no double-add, no error), and a fresh install still ships the column from its `CREATE TABLE`.
- `tests/Support/FakeWpdb.php` extended with `query()` (INSERT IGNORE + UNIQUE enforcement, refund UPDATE), `get_row()`, `rows_affected`, parsed column DEFAULTs, and `SHOW COLUMNS` / `SHOW INDEX` / `ALTER TABLE ADD COLUMN|KEY` plus parsed per-table column/index registries so the shim mirrors the real constraint/atomic/DDL behaviour (and the upgrade-path test exercises both the present and missing branches).

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
