#!/usr/bin/env bash
#
# SDK stability self-audit.
#
# Runs every check that gates a stable SDK release in one shot. Fails fast on
# the first hard error so CI surfaces "what broke" without scrolling. Each
# check echoes a single PASS or FAIL line plus a short reason.
#
# What this catches:
#   - PHP syntax errors (php -l) across every file in src/.
#   - PHPUnit suite — currently 48 tests / 97 assertions.
#   - composer.json validity.
#   - Class loader coherence: every entry in $wbcom_credits_sdk_classes maps
#     to a real file on disk. A typo in the map shows up here, not at
#     `require_once` time in production.
#   - Version coherence: WBCOM_CREDITS_SDK_VERSION in the bootstrap matches
#     the topmost released entry in CHANGELOG.md (or [Unreleased] if the
#     bump is still in flight).
#   - Required documentation present: setup guides + migration playbooks
#     referenced from CHANGELOG actually exist.
#   - Public API surface snapshot: enumerates every `public function` and
#     `public const` in src/ into a sorted manifest so a follow-up step
#     (or human eyeball) can diff vs the last release.
#
# Exit code 0 on full PASS. Non-zero on first FAIL.
#
# Usage:
#   bin/audit.sh
#   bin/audit.sh --skip-tests  # for fast iteration when you've already run phpunit

set -euo pipefail

SDK_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$SDK_ROOT"

SKIP_TESTS=0
for arg in "$@"; do
    case "$arg" in
        --skip-tests) SKIP_TESTS=1 ;;
        --help|-h)
            sed -n '/^# Usage:/,/^$/p' "$0"
            exit 0
            ;;
        *) echo "✗ Unknown flag: $arg" >&2; exit 2 ;;
    esac
done

PASS_COUNT=0
FAIL_COUNT=0

pass() { echo "✓ $*"; PASS_COUNT=$((PASS_COUNT + 1)); }
fail() { echo "✗ $*" >&2; FAIL_COUNT=$((FAIL_COUNT + 1)); }
section() { echo; echo "── $* ──"; }

# ─── 1. PHP lint ────────────────────────────────────────────────────────────
section "PHP syntax (php -l)"

LINT_ERRORS=0
while IFS= read -r -d '' file; do
    if ! php -l "$file" >/dev/null 2>&1; then
        echo "    syntax error: $file"
        LINT_ERRORS=$((LINT_ERRORS + 1))
    fi
done < <(find src bin tests -type f -name '*.php' -print0 2>/dev/null || true)

if ! php -l wbcom-credits-sdk.php >/dev/null 2>&1; then
    echo "    syntax error: wbcom-credits-sdk.php"
    LINT_ERRORS=$((LINT_ERRORS + 1))
fi

if [ "$LINT_ERRORS" -eq 0 ]; then
    pass "every PHP file parses"
else
    fail "$LINT_ERRORS file(s) with syntax errors"
fi

# ─── 2. Composer manifest ───────────────────────────────────────────────────
section "Composer manifest"

if composer validate --strict --no-check-publish >/dev/null 2>&1; then
    pass "composer.json is strict-valid"
else
    fail "composer.json failed strict validation"
    composer validate --strict --no-check-publish 2>&1 | sed 's/^/    /'
fi

# ─── 3. PHPUnit ─────────────────────────────────────────────────────────────
if [ "$SKIP_TESTS" -eq 0 ]; then
    section "PHPUnit suite"
    if [ ! -x vendor/bin/phpunit ]; then
        fail "vendor/bin/phpunit not installed — run \`composer install\`"
    elif vendor/bin/phpunit --colors=never >/tmp/wcb-sdk-phpunit.log 2>&1; then
        SUMMARY="$(grep -E '^(OK|FAILURES)' /tmp/wcb-sdk-phpunit.log | tail -1)"
        pass "PHPUnit: $SUMMARY"
    else
        fail "PHPUnit suite failed"
        tail -20 /tmp/wcb-sdk-phpunit.log | sed 's/^/    /'
    fi
else
    section "PHPUnit suite (skipped via --skip-tests)"
fi

# ─── 3a. PHPStan ────────────────────────────────────────────────────────────
section "Static analysis (PHPStan)"
if [ ! -x vendor/bin/phpstan ]; then
    fail "vendor/bin/phpstan not installed — run \`composer install\`"
elif vendor/bin/phpstan analyse --no-progress --memory-limit=1G >/tmp/wcb-sdk-phpstan.log 2>&1; then
    pass "PHPStan: $(grep -E '^\[OK\]|No errors' /tmp/wcb-sdk-phpstan.log | tail -1)"
else
    ERR_COUNT="$(grep -oE 'Found [0-9]+ errors?' /tmp/wcb-sdk-phpstan.log | tail -1)"
    fail "PHPStan reported $ERR_COUNT — see /tmp/wcb-sdk-phpstan.log"
    tail -20 /tmp/wcb-sdk-phpstan.log | sed 's/^/    /'
fi

# ─── 4. Class loader coherence ──────────────────────────────────────────────
section "Class loader map vs filesystem"

# Extract every `'\Wbcom\Credits\…' => __DIR__ . '/src/…'` line from the loader,
# then check each target file exists.
MISSING_CLASSES=0
while IFS= read -r line; do
    # Strip everything up to the '/' so we get just the relative path.
    rel="$(echo "$line" | sed -E "s#.*__DIR__ \. '([^']+)'.*#\1#")"
    if [ ! -f "$SDK_ROOT$rel" ]; then
        echo "    missing: $rel"
        MISSING_CLASSES=$((MISSING_CLASSES + 1))
    fi
done < <(grep -E "__DIR__ \. '/src/" wbcom-credits-sdk.php || true)

if [ "$MISSING_CLASSES" -eq 0 ]; then
    pass "every class-loader entry resolves on disk"
else
    fail "$MISSING_CLASSES class-loader entries missing on disk"
fi

# ─── 5. Version coherence ───────────────────────────────────────────────────
section "Version coherence"

BOOTSTRAP_VERSION="$(grep -oE "WBCOM_CREDITS_SDK_VERSION', '[^']+'" wbcom-credits-sdk.php | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
HEADER_VERSION="$(grep -E '^\s*\*\s*@version' wbcom-credits-sdk.php | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
CHANGELOG_TOP="$(grep -E '^## \[' CHANGELOG.md | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+|Unreleased')"

if [ -n "$BOOTSTRAP_VERSION" ] && [ -n "$HEADER_VERSION" ] && [ "$BOOTSTRAP_VERSION" = "$HEADER_VERSION" ]; then
    pass "bootstrap @version + WBCOM_CREDITS_SDK_VERSION agree on $BOOTSTRAP_VERSION"
else
    fail "version mismatch — @version='$HEADER_VERSION' vs constant='$BOOTSTRAP_VERSION'"
fi

if [ "$CHANGELOG_TOP" = "Unreleased" ]; then
    pass "CHANGELOG topmost section is [Unreleased] (pre-release state)"
elif [ "$CHANGELOG_TOP" = "$BOOTSTRAP_VERSION" ]; then
    pass "CHANGELOG topmost section matches bootstrap version $BOOTSTRAP_VERSION"
else
    fail "CHANGELOG top section ('$CHANGELOG_TOP') doesn't match bootstrap ('$BOOTSTRAP_VERSION')"
fi

# Also check that the version-specific functions in the bootstrap use the
# same number. Catches `register_1_3_0` ↔ '1.2.0' constant drift.
FN_VERSION="$(grep -oE 'wbcom_credits_sdk_register_[0-9]+_[0-9]+_[0-9]+' wbcom-credits-sdk.php | head -1 | grep -oE '[0-9]+_[0-9]+_[0-9]+' | tr '_' '.')"
if [ -z "$FN_VERSION" ]; then
    fail "couldn't extract version from wbcom_credits_sdk_register_*"
elif [ "$FN_VERSION" = "$BOOTSTRAP_VERSION" ]; then
    pass "version-specific function names use $FN_VERSION"
else
    fail "function suffix '$FN_VERSION' doesn't match bootstrap '$BOOTSTRAP_VERSION'"
fi

# ─── 6. Required documentation ──────────────────────────────────────────────
section "Documentation presence"

REQUIRED_DOCS=(
    "README.md"
    "CHANGELOG.md"
    "PORTFOLIO-PLAN.md"
    "docs/SETUP-STRIPE.md"
    "docs/SETUP-PAYPAL.md"
    "docs/MIGRATION-1.3.0-pricing.md"
    "docs/MIGRATION-1.3.0-career-board.md"
    "docs/CONSUMER_GATEWAY_INTEGRATION.md"
)
DOC_MISSING=0
for doc in "${REQUIRED_DOCS[@]}"; do
    if [ ! -s "$doc" ]; then
        echo "    missing or empty: $doc"
        DOC_MISSING=$((DOC_MISSING + 1))
    fi
done
if [ "$DOC_MISSING" -eq 0 ]; then
    pass "all ${#REQUIRED_DOCS[@]} required docs present + non-empty"
else
    fail "$DOC_MISSING required docs missing or empty"
fi

# ─── 7. Public API surface snapshot ─────────────────────────────────────────
section "Public API surface snapshot"

SNAPSHOT_FILE="bin/.api-surface.txt"
TMP_SNAPSHOT="/tmp/wcb-sdk-api-surface-$$.txt"

# Extract every public method + public const from src/, sort, output.
{
    find src -name '*.php' -type f | sort | while read -r f; do
        rel="${f#src/}"
        grep -nE '^\s*(public|public static)\s+(function|const)\s+\w+' "$f" 2>/dev/null \
            | sed -E "s#^([0-9]+):\s*#${rel}:\1 — #" \
            | sed -E 's#\s+\{.*##' \
            | sed -E 's#\s+$##'
    done
} > "$TMP_SNAPSHOT"

API_COUNT="$(wc -l < "$TMP_SNAPSHOT" | tr -d ' ')"
pass "enumerated $API_COUNT public symbols in src/"

if [ -f "$SNAPSHOT_FILE" ]; then
    if diff -q "$SNAPSHOT_FILE" "$TMP_SNAPSHOT" >/dev/null 2>&1; then
        pass "public API surface unchanged since last snapshot"
    else
        ADDED="$(diff "$SNAPSHOT_FILE" "$TMP_SNAPSHOT" | grep -cE '^> ' || true)"
        REMOVED="$(diff "$SNAPSHOT_FILE" "$TMP_SNAPSHOT" | grep -cE '^< ' || true)"
        # Removals = breaking. Additions = minor bump warranted.
        if [ "$REMOVED" -gt 0 ]; then
            fail "public API surface SHRANK by $REMOVED symbol(s) — breaking change. Run \`mv $TMP_SNAPSHOT $SNAPSHOT_FILE\` after a deliberate major bump."
            diff "$SNAPSHOT_FILE" "$TMP_SNAPSHOT" | head -30 | sed 's/^/    /'
        else
            pass "public API surface GREW by $ADDED symbol(s) — minor bump warranted (no breaking removals)"
        fi
    fi
else
    cp "$TMP_SNAPSHOT" "$SNAPSHOT_FILE"
    pass "first-run: snapshotted public API surface to $SNAPSHOT_FILE"
fi

rm -f "$TMP_SNAPSHOT"

# ─── Summary ────────────────────────────────────────────────────────────────
echo
echo "═══════════════════════════════════════════════"
echo "  Pass: $PASS_COUNT     Fail: $FAIL_COUNT"
echo "═══════════════════════════════════════════════"

if [ "$FAIL_COUNT" -gt 0 ]; then
    exit 1
fi

echo "SDK stability self-audit ✅ PASS"
