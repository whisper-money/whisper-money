# Autoresearch Dashboard: ai-rule-suggestion-coverage

**Runs:** 5 | **Kept:** 5 | **Discarded:** 0 | **Crashed:** 0

**Headline:** live-model coverage (real_tx) **416 → ~925** of 1329 tx (31% → 70%).

Segment 0 primary was oracle_tx (saturated near 1329). Segment 1 primary is
reachable_tx (realizable ceiling); real_tx is milestone ground truth.

Ground truth (live Gemini, median of 3):
- baseline (40 groups): 416 (437/410/400)
- 150 groups single payload: 711 (711/214/774) — unstable tail
- 150 groups + batching: 903 (970/903/885)
- + frequency grouping: 925 (950/900/925)

| # | seg | commit | reachable_tx | oracle_tx | real_tx | status | description |
|---|-----|--------|--------------|-----------|---------|--------|-------------|
| 1 | 0 | 592afa7 | 515 | 830 | 416 | keep | baseline + frozen oracle benchmark scaffold |
| 2 | 0 | 4fcbaed | 828 | 1229 | 711 | keep | max_groups_sent 40->150 |
| 3 | 0 | 8e56467 | 828 | 1229 | 903 | keep | batch Gemini calls (group_batch_size=40) |
| 4 | 0 | 5b644a0 | 1027 | 1222 | 925 | keep | language-agnostic frequency grouping |
| 5 | 1 | 5b644a0 | 1027 | 1222 | 925 | keep | segment baseline (primary -> reachable_tx) |
