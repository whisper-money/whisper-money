# Autoresearch Dashboard: ai-rule-suggestion-coverage

**Status:** STOPPED by user after run 7.
**Runs:** 7 | **Kept:** 7 | **Discarded:** 0 | **Crashed:** 0

**Headline:** live-model coverage for victoor89@gmail.com
**real_tx 416 → 1122** of 1329 uncategorized tx (**31% → 84%**).

Segment 0 primary = oracle_tx (saturated near 1329 by run 2).
Segment 1 primary = reachable_tx (realizable ceiling). real_tx = live-Gemini
ground truth (median of repeated runs; the user-facing number).

Note: the small per-run commits were later squashed into two feature commits
(`max_groups_sent 40->150` and `resilient batched generation`); the `commit`
column below is historical. Final code state is at HEAD `133c95f0`.

Ground truth (live Gemini) progression:
- baseline (40 groups): 416 (437/410/400)
- 150 groups, single payload: 711 (711/214/774) — unstable tail
- 150 groups + batching: 903 (970/903/885)
- + frequency grouping: 925 (950/900/925)
- + send all groups (min1/cap500): 1124, then 1122 (resilient) — 84%

| # | seg | reachable_tx | oracle_tx | real_tx | status | description |
|---|-----|--------------|-----------|---------|--------|-------------|
| 1 | 0 | 515 | 830 | 416 | keep | baseline + frozen oracle benchmark |
| 2 | 0 | 828 | 1229 | 711 | keep | max_groups_sent 40->150 |
| 3 | 0 | 828 | 1229 | 903 | keep | batch Gemini calls (group_batch_size=40) |
| 4 | 0 | 1027 | 1222 | 925 | keep | language-agnostic frequency grouping |
| 5 | 1 | 1027 | 1222 | 925 | keep | segment baseline (primary -> reachable_tx) |
| 6 | 1 | 1329 | 1329 | 1124 | keep | send all groups (min_group_count=1, cap=500) |
| 7 | 1 | 1329 | 1329 | 1122 | keep | resilient batched generation + tests |

## What shipped (all language-agnostic, pan-EU safe)
1. `max_groups_sent` 40 → 150 then cap raised; send every group.
2. `min_group_count` 2 → 1 (include one-off merchants).
3. Batch the Gemini call (`group_batch_size`=40) so a large payload doesn't make
   the model under-enumerate.
4. Frequency-based description grouping (drop tokens appearing in >2% of the
   user's tx) — merges merchant variants in any language; no wordlists.
5. Resilient batching: retry once, tolerate partial-batch failure, only error
   if every batch fails.

## Cost / UX tradeoff to decide before shipping defaults
min_group_count=1 + cap=500 sends ~441 groups → ~12 Gemini calls per run and
creates one-off rules for single transactions. It buys ~+200 real
categorizations (925→1122). A more economical default (min_group_count=2,
cap~200, reachable≈1049, ~5 calls, recurring merchants only) captures most of
the gain without the one-off-rule clutter. See worklog "Next Ideas".
