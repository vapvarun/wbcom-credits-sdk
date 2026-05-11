# Migration: wp-career-board-pro → SDK 1.3.0 schema contract

**Audience:** maintainers of `wp-career-board-pro`. Not user-facing.

**Why this exists:** SDK 1.3.0 clarifies the Ledger schema as a hard contract — the canonical columns are `user_id` and `item_id`. Consumer plugins must NOT pre-empt the SDK's `Ledger::maybe_create_table()` by shipping their own schema. Career Board Pro 1.1.0 currently does, and its custom `employer_id`/`post_id` columns silently break every SDK query against the ledger table.

The 2026-05-11 live verification surfaced this: `Ledger::get_balance('wcb', 1)` returned `0` with an `Unknown column 'user_id'` DB warning because Career Board's table only has `employer_id`.

This document is the playbook for the wp-career-board-pro maintainer to bring the plugin into compliance.

---

## What changes in wp-career-board-pro

### 1. Drop the custom ledger CREATE TABLE

In `core/class-pro-install.php` around line 441, remove the block that creates `wp_wcb_credit_ledger`. The SDK's `Ledger::maybe_create_table('wcb')` already runs during `Registry::register()` and produces the canonical schema.

```diff
-		dbDelta( "CREATE TABLE {$wpdb->prefix}wcb_credit_ledger (
-			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
-			employer_id BIGINT UNSIGNED NOT NULL,
-			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
-			...
-		) {$charset_collate};" );
+		// Ledger schema is owned by wbcom-credits-sdk — do not duplicate.
+		// SDK auto-creates wp_wcb_credit_ledger with the canonical schema
+		// during Registry::register() in wp-career-board-pro.php.
```

### 2. Replace `employer_id` references with `user_id`

Every query, insert, and `$wpdb->delete()` call that touches `wcb_credit_ledger` and currently uses `employer_id` must read/write `user_id` instead. Files:

```
core/class-data-cleanup.php:162           $wpdb->delete('...wcb_credit_ledger', ['employer_id' => $user_id])
api/endpoints/class-analytics-endpoint.php:67   SELECT id, employer_id, amount, ...
modules/credits/class-credit-reconciler.php:140 ... reconciliation query joining on employer_id
```

Each becomes `user_id` — the semantic is identical (it's the WP user ID of the posting employer; the column is just being renamed to the SDK's canonical name).

### 3. Replace `post_id` references with `item_id`

Same treatment for the second column. `post_id` is currently the job-post ID; in SDK terms that's the "item" the credit was spent on.

```
core/class-pro-install.php:349            mapping table for cleanup
api/endpoints/class-analytics-endpoint.php:67   SELECT ... post_id ...
modules/credits/class-credit-reconciler.php:140 ... reconciliation queries
```

### 4. One-shot data migration

For existing installs running 1.1.0 with the old column names, ship a migration in the next Career Board Pro release that:

```sql
-- Run inside a transaction.
ALTER TABLE wp_wcb_credit_ledger CHANGE COLUMN employer_id user_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE wp_wcb_credit_ledger CHANGE COLUMN post_id item_id BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE wp_wcb_credit_ledger DROP INDEX IF EXISTS idx_employer_id;
ALTER TABLE wp_wcb_credit_ledger ADD INDEX idx_user_id (user_id);
```

Wrap in a `dbDelta`-compatible upgrade hook keyed on a `wcb_credit_ledger_schema_version` option. On first activation post-upgrade:

1. Check `get_option('wcb_credit_ledger_schema_version', 0) < 2`.
2. Run the `ALTER TABLE` statements above.
3. Bump `wcb_credit_ledger_schema_version` to `2`.

Idempotent — running twice is a no-op.

### 5. Domain-readable accessors stay

If `employer_id` reads better in the plugin's public API (CLI commands, REST shapes, dashboards), keep that as the **outward-facing name**. The SDK only cares about the database column; the consumer plugin can alias internally:

```php
$balance = \Wbcom\Credits\Credits::get_balance( 'wcb', $employer_id );
// ↑ The SDK sees user_id, the plugin still calls the param $employer_id.
```

No domain language is lost — only the column name is reconciled.

---

## Validation checklist

Before merging the Career Board migration:

- [ ] `class-pro-install.php` no longer issues `CREATE TABLE wcb_credit_ledger`.
- [ ] Every reference to `employer_id` in queries against `wcb_credit_ledger` is replaced with `user_id`.
- [ ] Every reference to `post_id` in queries against `wcb_credit_ledger` is replaced with `item_id`.
- [ ] Migration runs on a clean upgrade from 1.1.0 and brings the schema in line.
- [ ] On a fresh install, the SDK's `Ledger::maybe_create_table('wcb')` creates the table; the plugin doesn't.
- [ ] After upgrade, `Credits::get_balance('wcb', $user_id)` returns the correct number (no `Unknown column` DB warning).
- [ ] The plugin's outward-facing API (REST shape, analytics endpoint, admin UI) still exposes `employer_id` for backwards compatibility with any external integrations.

---

## SDK-side gates that catch regressions

- `tests/Ledger/SchemaContractTest.php` — locks the canonical columns at the SDK level. If someone ever tries to template column names again, this test fails before merge.
- `PORTFOLIO-PLAN.md` — Option B decision recorded.
- This document — the playbook itself.

If wp-career-board-pro ships v1.3.0 of the SDK without completing this migration, every credit operation in production will hit `Unknown column 'user_id'` from `Ledger::get_balance()` and similar. Don't bundle SDK 1.3.0 until the migration is verified on a staging copy.
