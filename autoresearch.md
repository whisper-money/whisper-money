# Autoresearch: CI total execution time

## Objective
Reduce GitHub Actions CI wall-clock completion time for pull requests without weakening coverage or hiding failures.

## Metrics
- **Primary**: ci_total_s (s, lower is better) — modeled PR workflow critical path from current `.github/workflows/ci.yml` plus recent successful CI job timings.
- **Secondary**: build_assets_s, tests_s, browser_matrix_s, linter_s, static_analysis_s, performance_tests_s, job_count — tradeoff and bottleneck monitors.

## How to Run
`./autoresearch.sh` — validates workflow structure, samples recent successful `CI` pull_request runs through `gh`, and outputs `METRIC name=value` lines.

## Files in Scope
- `.github/workflows/ci.yml` — CI graph, job dependencies, sharding, commands.
- `.github/actions/setup-php-deps/action.yml` — PHP/composer setup cache behavior.
- `.github/actions/setup-bun-deps/action.yml` — Bun/node setup cache behavior.
- `phpunit.xml`, `tests/Pest.php` — test suite partitioning only when coverage remains equivalent.
- `package.json`, `composer.json` — CI scripts only, no dependency changes without approval.

## Off Limits
- No deleting or skipping real checks to win time.
- No weakening assertions, lowering static-analysis level, or excluding tests unless moved to an equivalent job.
- No production secrets or `.env` reads.
- No dependency changes without explicit approval.

## Constraints
- Small experiments.
- Keep improvements only when primary metric improves.
- Preserve CI correctness and failure visibility.
- Prefer workflow graph/cache improvements before test-suite rewrites.

## What's Been Tried
- Baseline pending.
