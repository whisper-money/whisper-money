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

## Key Insights
- `max_groups_sent=40` is the first hard cap: reachable=515 of a possible 830
  at count≥2. Lifting it is the cheapest ceiling win.
- Tokens legitimately span multiple groups (same merchant, different noise), so
  oracle_tx (830) > reachable_tx (515) — token extraction is high-value.
- Real model is conservative (skips groups). Prompt/batching may matter as much
  as thresholds, but must be judged by real_tx.

## Next Ideas
- See autoresearch.md Idea Backlog.
