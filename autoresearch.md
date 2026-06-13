# Autoresearch: AI rule-suggestion coverage

## Objective
Maximize how many of a user's uncategorized transactions the AI rule-suggestion
pipeline can categorize. Driven by `php artisan ai:suggest-rules <user>`.

Workload: user `victoor89@gmail.com`, reset to a clean slate (0 automation
rules, **1329** uncategorized transactions, all server-readable, 64 categories).
The data is description-dominated: ~94% of groups key off the free-text
`description` field (noisy Spanish bank descriptors); only ~62 transactions
carry a `creditor_name`/`debtor_name`.

The pipeline: `RuleSuggestionAggregator` groups uncategorized tx → caps to
`max_groups_sent` groups with count ≥ `min_group_count` → Gemini maps groups to
(field, operator, token, category) suggestions → `RuleSuggestionGuard` validates
(token ≥3 chars, literal match ≥1, not over-broad > `overbroad_fraction`,
confidence ≥ `confidence_floor`, category direction agrees) → persisted as rules.

## Metrics
- **Primary**: `oracle_tx` (count, higher is better) — distinct uncategorized tx
  that an ideal model + the REAL guard would categorize, given the groups the
  REAL aggregator sends. Deterministic, zero-variance, instant. Measured by
  `experiments/bench.php` with a FROZEN oracle (distinctive-token picker).
- **Secondary**:
  - `reachable_tx` — tx living in the groups the aggregator sends (hard ceiling).
  - `groups_sent`, `validated_count`, `coverage_pct`.
  - `real_tx` — GROUND TRUTH: distinct tx the live Gemini run + guard categorize.
    Noisy (~±30 over runs: 437/410/400 at baseline). Measured at milestones only
    via `experiments/calibrate_real.php` (one Gemini call); not in the loop.

## How to Run
`./autoresearch.sh` — outputs `METRIC name=number` lines (oracle benchmark).
Milestone ground truth: `php artisan tinker experiments/calibrate_real.php`.

## Files in Scope
- `config/ai_suggestions.php` — thresholds (max_groups_sent, min_group_count,
  confidence_floor, overbroad_fraction). **Single source of truth**: the tunable
  `AI_SUGGESTIONS_*` overrides were removed from `.env`, so editing the config
  default here actually takes effect. Threshold experiments live here.
- `app/Services/Ai/RuleSuggestionAggregator.php` — grouping + key normalization
  (the clustering lever; better merchant extraction merges more tx into bigger
  groups so they pass min_group_count and produce clean tokens).
- `app/Services/Ai/RuleSuggestionGuard.php` — validation logic.
- `app/Ai/Agents/RuleSuggestionAgent.php` — the prompt (realization lever;
  judge ONLY via `real_tx`, never oracle, since the oracle assumes ideal model).
- `app/Services/Ai/LaravelAiRuleSuggestionGenerator.php` — batching the model
  call would let us send more groups without one giant payload.

## Off Limits
- `experiments/bench.php` oracle token heuristic + stoplist are FROZEN. Improving
  them games the metric. Only real pipeline code/config may change.
- No deleting/weakening tests. No new dependencies without approval.
- Do not re-add `AI_SUGGESTIONS_*` threshold overrides to `.env`.
- Do not touch the user's DB rows further (already reset).

## Constraints
- LANGUAGE-AGNOSTIC: the user base is pan-European (ES/DE/FR/IT/…), so NO product
  change may hardcode Spanish (or any single-language) wordlists. Clustering /
  noise removal must be statistical (e.g. per-user token frequency) or delegated
  to the multilingual model. The Spanish stoplist in `experiments/bench.php` is a
  measurement-only artifact for this one Spanish user — never ship its approach.
- Every kept change must keep the AI test suite green
  (`php artisan test --compact tests/Feature/Ai`).
- Keep PHP style (`vendor/bin/pint --dirty`).
- Threshold/clustering wins are judged by `oracle_tx`. Prompt/batching wins are
  judged by `real_tx` (median of 2–3 runs, since it is noisy).
- Validate `real_tx` after any kept ceiling change to confirm the live model
  actually realizes part of the new ceiling (oracle is only an upper bound).

## What's Been Tried
- Baseline (max_groups_sent=40, min_group_count=2, overbroad=0.4, floor=0.3):
  `oracle_tx=830`, `reachable_tx=515`, `groups_sent=40`, real_tx≈416 (median
  437/410/400). The live model covers only ~25 of 40 groups → big gap between
  real (≈416) and the oracle ceiling (830). Two independent levers:
  raise the ceiling (aggregation/clustering) AND close the realization gap
  (prompt/batching).

## Idea Backlog (rough priority)
1. [DONE r2] max_groups_sent 40 → 150 (covers all count≥2 groups).
2. [DONE r3] Batch the Gemini call so a big payload doesn't make the model
   under-enumerate (real_tx).
3. LANGUAGE-AGNOSTIC clustering: strip noise tokens by per-user document
   frequency (words shared across many of THIS user's groups are noise in any
   language), not by a hardcoded wordlist. Merges merchant variants → more
   count≥2 groups, cleaner tokens. Replaces the old "strip Spanish noise" idea.
4. min_group_count 2 → 1 (adds 499 singleton groups; ceiling toward 1300).
5. Tune overbroad_fraction / confidence_floor.
6. Prompt: insist on covering EVERY group + multilingual merchant-token
   extraction (real_tx).
7. Use AI over all transaction descriptions (CSV-style) to discover groups —
   user idea; explore as an alternative to PHP pre-aggregation.
