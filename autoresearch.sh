#!/usr/bin/env bash
#
# Autoresearch benchmark: AI rule-suggestion coverage for a single user.
#
# Runs the DETERMINISTIC oracle benchmark (experiments/bench.php) which exercises
# the real aggregator + guard + config and emits METRIC lines. The live Gemini
# call is NOT used here (too slow / nondeterministic for a tight loop); ground
# truth is measured separately at milestones via experiments/calibrate_real.php.
#
# Primary metric: oracle_tx (distinct uncategorized tx an ideal model+guard
# would categorize given the groups the aggregator sends). Higher is better.

set -euo pipefail

cd "$(dirname "$0")"

export BENCH_USER="${BENCH_USER:-victoor89@gmail.com}"

# Fast syntax precheck (<1s) on the files experiments touch.
php -l experiments/bench.php >/dev/null
php -l app/Services/Ai/RuleSuggestionAggregator.php >/dev/null
php -l app/Services/Ai/RuleSuggestionGuard.php >/dev/null
php -l config/ai_suggestions.php >/dev/null

OUT="$(php artisan tinker experiments/bench.php 2>&1)"

if ! grep -q '^METRIC oracle_tx=' <<<"$OUT"; then
    echo "BENCH FAILED:" >&2
    echo "$OUT" >&2
    exit 1
fi

grep -E '^METRIC ' <<<"$OUT"
