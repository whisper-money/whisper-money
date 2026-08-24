# Autoresearch Worklog: AI rule-suggestion coverage

**Session goal:** maximize uncategorized tx the `ai:suggest-rules` pipeline can
categorize for `victoor89@gmail.com`.

## Data summary (clean slate)
- 1329 uncategorized tx (all server-readable), 64 categories, 0 rules.
- 650 distinct normalized groups; 499 singletons; 151 groups with count≥2
  (covering 830 tx); 90 groups count≥3 (708 tx).
- Description-dominated: ~1267 tx key off `description`, ~62 off creditor/debtor.
- Ceiling map (sum of top-N group counts): top40=515, top60=602, top100=728,
  top150=828, top200=879, top300=979, all650=1300.

## Metric
- Primary `oracle_tx` (deterministic, frozen oracle in experiments/bench.php).
- Ground truth `real_tx` (live Gemini, noisy, milestone-only).

---

### Run 1: baseline — oracle_tx=830 (KEEP)
- What changed: nothing; scaffold + frozen oracle benchmark established.
- Result: oracle_tx=830, reachable_tx=515, groups_sent=40, validated_count=29,
  coverage_pct=62.45. Ground truth real_tx median ≈416 (437/410/400).
- Insight: the live model realizes only ~half the oracle ceiling and ~80% of
  reachable; it covers ~25 of 40 sent groups. Both the ceiling (aggregation)
  and the realization gap (prompt/batching) are open levers.
- Next: raise max_groups_sent toward 150 (cover all count≥2 groups).

### Run 2: max_groups_sent 40 -> 150 — oracle_tx=1229 (KEEP)
- Timestamp: 2026-06-13
- What changed: config/ai_suggestions.php default max_groups_sent 40 -> 150.
- Result: oracle_tx 830->1229 (+48%), reachable 515->828, groups_sent 150,
  coverage 92.48%. Tests 24/24 green. Real_tx (3 runs): 711 / 214 / 774,
  median 711 (baseline 416).
- Insight: ceiling lift works AND the median real run nearly doubles. BUT the
  live model is now UNSTABLE on the big single payload — one run returned only
  9 suggestions (real_tx 214 < baseline). gemini-flash under-enumerates a large
  structured-output request. The realization axis is now the bottleneck.
- Next: BATCH the Gemini call (chunk groups into reliable-size requests, merge
  raw suggestions). Should stabilize + realize the high ceiling. Judge by
  real_tx (oracle unaffected — same groups/guard).

### Run 3: batch Gemini calls (group_batch_size=40) — real_tx 416->903 (KEEP)
- Timestamp: 2026-06-13
- What changed: LaravelAiRuleSuggestionGenerator now array_chunks groups into
  group_batch_size(40) per-request batches and merges suggestions. New config
  key ai_suggestions.group_batch_size.
- Result: oracle_tx unchanged (1229 — bench bypasses generator). real_tx (3
  runs): 970/903/885, median 903; raw suggestions ~125 stable (was 9..50);
  validated ~88. Tests 24/24 green, pint clean.
- Insight: the 150-group instability was purely a single-payload enumeration
  failure. Smaller batches make the multilingual model reliably enumerate every
  group. Real coverage 416->903 (+117%), now 68% of all tx and 74% of the oracle
  ceiling (was 50%). Decision metric here is real_tx (primary oracle unmoved).
- Next: language-agnostic clustering (per-user token document-frequency noise
  removal) to merge merchant variants — must NOT hardcode Spanish (pan-EU users).

### Run 4: language-agnostic frequency-based grouping — reachable 828->1027 (KEEP)
- Timestamp: 2026-06-13
- What changed: RuleSuggestionAggregator keys descriptions on distinctive
  (low document-frequency) tokens; drops words in >noise_token_fraction(2%) of
  tx (language-agnostic, no wordlist); fallback keeps all if all common. New
  config ai_suggestions.noise_token_fraction.
- Result: reachable_tx 828->1027 (singletons 499->291), validated 72->115,
  oracle_tx ~flat (1229->1222, saturated). real_tx (3 runs) 950/900/925 median
  925 — FLAT vs batching's 903 (mean 919 vs 925). Tests 24/24, pint clean.
- Insight: clustering raises the realizable ceiling (+199 reachable) but the
  live model already covered most via good tokens, so real_tx barely moved THIS
  step. The headroom matters for the next levers. oracle_tx is now saturated
  near the 1329 total and is a poor primary.
- Decision: KEEP (language-agnostic per user steer, cleaner groups, +200
  reachable headroom, no real regression).

### Segment switch (run 5): primary metric -> reachable_tx
- oracle_tx saturated (~1222/1329); switched the deterministic loop primary to
  reachable_tx (sensitive to clustering). real_tx stays the milestone ground
  truth; prompt/batch experiments are judged by real_tx regardless.
- Segment-1 baseline: reachable_tx=1027, real_tx≈925.

### Run 6: send all groups (min_group_count=1, max_groups_sent=500) — reachable 1329 (KEEP)
- What changed: config min_group_count 2->1, max_groups_sent 150->500.
- Result: reachable_tx 1027->1329 (100%), groups_sent 441. real_tx=1124 (84.6%,
  one clean sample; 2 of 3 sample runs failed operationally — 12 batches/run is
  slow + fragile, one failed batch lost the whole run). Tests green.
- Insight: singletons (one-off merchants) ARE realizable — adding them lifted
  real coverage 925->1124. But high batch counts need resilience.

### Run 7: resilient batched generation — real_tx 1122 reliable (KEEP)
- What changed: LaravelAiRuleSuggestionGenerator retries each batch once,
  tolerates partial-batch failures (keeps successful batches), only rethrows if
  every batch fails. Added 3 generator tests.
- Result: reachable_tx 1329 (unchanged). real_tx=1122 reproducible (was 1124).
  Full AI suite 27/27, pint clean.
- Insight: the run-6 fragility was operational, not a model limit. With
  resilience the min1/cap500 config reliably lands ~1122 (84%).

### STOPPED by user after run 7.

## Final Result
- real_tx 416 -> 1122 of 1329 (31% -> 84%) for victoor89@gmail.com.
- All changes language-agnostic (pan-EU safe): frequency clustering, batching,
  caps, resilience. No hardcoded wordlists in product code.

## Key Insights
- `max_groups_sent=40` is the first hard cap: reachable=515 of a possible 830
  at count≥2. Lifting it is the cheapest ceiling win.
- Tokens legitimately span multiple groups (same merchant, different noise), so
  oracle_tx (830) > reachable_tx (515) — token extraction is high-value.
- Real model is conservative (skips groups). Prompt/batching may matter as much
  as thresholds, but must be judged by real_tx.

## Next Ideas (not yet tried)
- Decide the production default: aggressive min1/cap500 (max coverage, ~12
  Gemini calls/run, one-off rules) vs balanced min2/cap~200 (reachable≈1049,
  ~5 calls, recurring merchants only). Quantify real_tx for the balanced config.
- Prompt experiment (real_tx-judged): close the 1122->1329 gap by insisting on
  exhaustive mapping + multilingual merchant-token extraction.
- group_batch_size tuning (speed vs enumeration quality).
- Run batches concurrently to cut wall-clock of the many-batch config.
- noise_token_fraction sweep (0.015 looked marginally best deterministically).
