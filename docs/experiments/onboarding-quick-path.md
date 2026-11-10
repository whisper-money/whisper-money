# Experiment: onboarding quick path

The onboarding redesign that lets a user without a file still reach the spending
reveal, by typing last month in round figures. It shipped to **all users at
once, with no flag**, so this is a before/after comparison of two cohorts of
starters, not an A/B test. This page says what changed, what we measure, how to
query it, and when to call it.

Baseline measured on 2026-11-10. Results go at the bottom.

---

## What changed

- **A. Desktop import footer.** The import step ends in three actions, in this
  order of weight: primary _Choose a file_, secondary _No file? Type last month
  in round figures_, tertiary _Skip_.
- **A. Mobile opens on quick expenses.** On a phone the import step starts on the
  quick-expenses form instead of the file picker.
- **B. Live counter.** The quick-expenses form shows a running total against the
  user's `spending_guess` answer.
- **C. Estimated reveal.** A month that only holds estimates lands the
  `spending` reveal with `is_estimate=true`, worded as an estimate.
- **D. Second chance on the assets reveal.** The `assets` reveal offers _Add last
  month in round figures_, which opens quick expenses again.

`onboarding_quick_expenses_opened` now carries a `source` (`upload`, `default`,
`reveal`): opened from the import footer, opened by default on mobile, or opened
from the assets reveal.

### Confounders

Nothing was held back, so anything else that moved between the two periods moves
the numbers too:

- **Seasonality.** The "after" cohort runs from mid-November into December
  (Black Friday, the run-up to the holidays, end-of-year money planning); the
  "before" cohort is October to mid-November.
- **Pricing and paywall changes** shipped in the days before the deploy, all of
  them affecting the same new users:
    - [#1271](https://github.com/whisper-money/whisper-money/pull/1271),
      [#1272](https://github.com/whisper-money/whisper-money/pull/1272): latam
      price tier and region detection (2026-11-07).
    - [#1275](https://github.com/whisper-money/whisper-money/pull/1275): ask for
      Pro only once the user has data, behind a flag (2026-11-09). Estimates count
      as data, so it changes what an estimate-only user sees after onboarding.
    - [#1276](https://github.com/whisper-money/whisper-money/pull/1276): the high
      price is the price, the tier switch is gone (2026-11-09).
    - [#1279](https://github.com/whisper-money/whisper-money/pull/1279): no pay-now
      switch, no trial-days overrides, no self-service refund (2026-11-09).
- **Quick expenses itself.** [#1267](https://github.com/whisper-money/whisper-money/pull/1267)
  (2026-11-06) shipped the no-file path before this redesign, so the "before"
  period is a mix of the old flow and the first quick-expenses version.
- **Traffic mix.** A launch, a post or a campaign shifts device mix and intent.
  Compare the device split of both cohorts before reading the totals, and read
  the per-device rows when it moved.

Check `git log --since=<deploy - 35d> --oneline main` for anything else that
reached onboarding or pricing during either window and note it in the results.

---

## The deploy marker

`{deploy_at}` in every query below is the date of the PostHog annotation named
**`Onboarding quick-path redesign deployed`** (project-level), created right
after the production deploy.

Annotations are not a HogQL table, so `SELECT … FROM annotations` does not work.
Read it from either:

- **PostHog UI**: _Data management → Annotations_, search for the name, copy its
  date.
- **PostHog MCP**: `call annotations-list {"search": "Onboarding quick-path redesign deployed"}`
  and take `date_marker`.

If the annotation is missing, fall back to the merge commit on `main`
(`git log --merges --format='%H %cI %s' main | grep -i 'quick-path'`) and add a
few minutes for the deploy.

Write it as `YYYY-MM-DD HH:MM:SS` in UTC, e.g. `2026-11-12 10:30:00`.

---

## Baseline

Cohort: users whose first `onboarding_step_viewed` with `step=promise` was 7–60
days before 2026-11-10. Measured on 2026-11-10.

| Metric                                       | Value                                                         |
| -------------------------------------------- | ------------------------------------------------------------- |
| Starters                                     | 830 (457 desktop, 362 mobile, 11 tablet)                      |
| Reached the import screen                    | 414                                                           |
| `onboarding_import_completed`                | 63 (15% of import) — desktop 46/235 (20%), mobile 16/173 (9%) |
| `onboarding_reveal_shown` `variant=spending` | 91 (11% of starters)                                          |
| `onboarding_reveal_shown` `variant=assets`   | 311                                                           |
| Reached `complete`                           | 405 (49%)                                                     |

From the production database (users created 7–60 days before, n=1023):

| Segment                        | Users | Active after day 7 |
| ------------------------------ | ----- | ------------------ |
| Onboarded with transactions    | 213   | 105 (49%)          |
| Onboarded without transactions | 230   | 23 (10%)           |

22% of signups have any transaction at all.

> The baseline window ends on 2026-11-03, so it predates quick expenses (#1267,
> 2026-11-06) and measures the old file-only flow. The "before" cohort of the
> queries below will not match it exactly; report both.

---

## Metrics

### Primary

**Share of starters who see the spending reveal**: starters with any
`onboarding_reveal_shown` where `variant='spending'`, whatever its
`is_estimate`, over starters.

Baseline **11%**. Target **≥ 20%**.

### Secondary

- **Real vs estimated.** Split of spending reveals by `is_estimate`: how much of
  the lift is estimates, how much real files.
- **Quick expenses by source.** `onboarding_quick_expenses_submitted` over
  starters, attributed to the `source` of the `onboarding_quick_expenses_opened`
  that preceded it (`upload`, `default`, `reveal`).
- **Onboarding completion.** Starters who reach `step=complete`. Baseline 49%.

### Guardrails

- **Desktop file-import completion** must not drop. Desktop starters who
  reached the import screen and fired `onboarding_import_completed`. Baseline
  **20%**. The footer now offers a way out of uploading a file; it must not
  take users who had one.
- **Week-4 activity of starters.** Users with `last_active_at ≥ created_at + 21 days`,
  from the production database, cross-checked with a `$pageview` in days 21–28
  in PostHog. An estimate that lands the reveal but leaves an empty app is worth
  nothing. The pricing changes listed above land on the same users, so read this
  guardrail as "no clear drop", not as a precise delta.
- **Estimates followed by real movements.** Share of estimate-only users who
  import real movements within 30 days: any transaction that does not carry the
  estimate label. The label is the one `App\Services\Transactions\EstimateTransactions`
  creates, told apart by `labels.source = 'estimate'` (`App\Enums\LabelSource::Estimate`),
  never by its name.

---

## Queries

All PostHog queries are HogQL: paste them in _SQL_ (or `execute-sql` in the
MCP). Replace every `{deploy_at}` with the annotation date and every `{window}`
with the cohort length in days (35 by default, see [When to run](#when-to-run)).

They compare two exclusive cohorts of **starters**: people who viewed the
`promise` step, dated by their first `onboarding_step_viewed` (the same
definition as the baseline):

- `before`: started in `[deploy_at − window, deploy_at)`
- `after`: started in `[deploy_at, deploy_at + window)`

Events are scanned from 30 days before the `before` window, so someone who
started earlier and comes back is not counted as a new starter. The PostHog
project runs in UTC, like the app, so `{deploy_at}` means the same instant in
both systems.

The `starters` CTE is repeated in every query on purpose, so each block pastes
on its own. If you change the cohort definition, change it in all of them.

`is_estimate` and `source` come with the redesign. Before the first read,
confirm in PostHog (_Data management → Properties_) that they are captured
under those names: a renamed property fails silently here, every reveal would
read as `real` and every source as empty.

### Starters and funnel by device

Includes the desktop file-import guardrail (`import_completed` over `import`
on the `Desktop` rows; PostHog capitalises `$device_type`) and onboarding completion.

```sql
WITH starters AS (
    SELECT
        person_id,
        argMin(properties.$device_type, timestamp) AS device,
        min(timestamp) AS first_seen,
        if(first_seen < toDateTime('{deploy_at}'), 'before', 'after') AS period,
        countIf(properties.step = 'create-account') > 0 AS s_hub,
        countIf(properties.step = 'import-transactions') > 0 AS s_import,
        countIf(properties.step = 'reveal') > 0 AS s_reveal,
        countIf(properties.step = 'complete') > 0 AS s_complete
    FROM events
    WHERE event = 'onboarding_step_viewed'
        AND timestamp >= toDateTime('{deploy_at}') - toIntervalDay({window} + 30)
    GROUP BY person_id
    HAVING countIf(properties.step = 'promise') > 0
        AND first_seen >= toDateTime('{deploy_at}') - toIntervalDay({window})
        AND first_seen < toDateTime('{deploy_at}') + toIntervalDay({window})
),
imports AS (
    SELECT DISTINCT person_id
    FROM events
    WHERE event = 'onboarding_import_completed'
        AND timestamp >= toDateTime('{deploy_at}') - toIntervalDay({window})
)
SELECT
    period,
    device,
    count() AS started,
    sum(s_hub) AS hub,
    sum(s_import) AS import,
    countIf(s_import AND person_id IN (SELECT person_id FROM imports)) AS import_completed,
    round(100 * import_completed / nullIf(import, 0), 1) AS import_completed_pct,
    sum(s_reveal) AS reveal,
    sum(s_complete) AS complete,
    round(100 * complete / started, 1) AS complete_pct
FROM starters
GROUP BY period, device
ORDER BY period DESC, started DESC
```

Drop `device` from the `SELECT` and the `GROUP BY` for the totals.

### Primary: spending reveal, split by `is_estimate`

A person counts as `estimate_only` when every spending reveal they saw was an
estimate, and as `real` when at least one was not. Only reveals within 7 days of
the person's start count, so both cohorts get the same follow-up whenever the
query runs. `is_estimate` does not exist before the deploy, so every `before`
reveal reads as `real`, including the ones from the first quick-expenses version
(#1267): the `before` split is a mixed cohort.

```sql
WITH starters AS (
    SELECT
        person_id,
        min(timestamp) AS first_seen,
        if(first_seen < toDateTime('{deploy_at}'), 'before', 'after') AS period
    FROM events
    WHERE event = 'onboarding_step_viewed'
        AND timestamp >= toDateTime('{deploy_at}') - toIntervalDay({window} + 30)
    GROUP BY person_id
    HAVING countIf(properties.step = 'promise') > 0
        AND first_seen >= toDateTime('{deploy_at}') - toIntervalDay({window})
        AND first_seen < toDateTime('{deploy_at}') + toIntervalDay({window})
),
reveals AS (
    SELECT
        person_id,
        minIf(timestamp, toString(properties.variant) = 'spending') AS first_spending,
        minIf(
            timestamp,
            toString(properties.variant) = 'spending'
                AND coalesce(toString(properties.is_estimate), 'false') != 'true'
        ) AS first_spending_real,
        minIf(timestamp, toString(properties.variant) = 'assets') AS first_assets
    FROM events
    WHERE event = 'onboarding_reveal_shown'
        AND timestamp >= toDateTime('{deploy_at}') - toIntervalDay({window})
    GROUP BY person_id
)
SELECT
    s.period AS period,
    count() AS started,
    -- within 7 days of the start; a person with no such event reads 1970 and fails it
    countIf(r.first_spending BETWEEN s.first_seen AND s.first_seen + toIntervalDay(7)) AS spending_reveal,
    round(100 * spending_reveal / started, 1) AS spending_reveal_pct,
    countIf(r.first_spending_real BETWEEN s.first_seen AND s.first_seen + toIntervalDay(7)) AS spending_real,
    spending_reveal - spending_real AS spending_estimate_only,
    countIf(r.first_assets BETWEEN s.first_seen AND s.first_seen + toIntervalDay(7)) AS assets_reveal
FROM starters AS s
LEFT JOIN reveals AS r ON r.person_id = s.person_id
GROUP BY period
ORDER BY period DESC
```

`spending_reveal_pct` is the primary metric. `spending_reveal` and `assets_reveal`
can overlap: a person who used the second chance on the assets reveal (D) is in
both.

For the significance check of the decision rule, a two-proportion z-test on
`spending_reveal / started` of both rows is enough; any online calculator does
it. Report the 95% confidence interval of `after − before`.

### Secondary: quick expenses by source

Each person is attributed to the `source` of the last `opened` before their
first submit (or of their last `opened` if they never submitted). `opened`
counts people who opened or submitted the form. An empty or null `source` is
either an open from before the deploy, when the event had no `source`, or a
submit with no `opened` before it; after the deploy it should be rare, so report
its size if it is not.

```sql
WITH starters AS (
    SELECT
        person_id,
        min(timestamp) AS first_seen,
        if(first_seen < toDateTime('{deploy_at}'), 'before', 'after') AS period
    FROM events
    WHERE event = 'onboarding_step_viewed'
        AND timestamp >= toDateTime('{deploy_at}') - toIntervalDay({window} + 30)
    GROUP BY person_id
    HAVING countIf(properties.step = 'promise') > 0
        AND first_seen >= toDateTime('{deploy_at}') - toIntervalDay({window})
        AND first_seen < toDateTime('{deploy_at}') + toIntervalDay({window})
),
quick AS (
    SELECT
        person_id,
        submitted,
        -- the source of the last open before the first submit, or of the last open
        tupleElement(
            if(submitted, arrayLast(o -> tupleElement(o, 1) <= first_submit, opens), arrayLast(o -> 1, opens)),
            2
        ) AS source
    FROM (
        SELECT
            person_id,
            countIf(event = 'onboarding_quick_expenses_submitted') > 0 AS submitted,
            minIf(timestamp, event = 'onboarding_quick_expenses_submitted') AS first_submit,
            arraySort(
                o -> tupleElement(o, 1),
                groupArrayIf((timestamp, toString(properties.source)), event = 'onboarding_quick_expenses_opened')
            ) AS opens
        FROM events
        WHERE event IN ('onboarding_quick_expenses_opened', 'onboarding_quick_expenses_submitted')
            AND timestamp >= toDateTime('{deploy_at}') - toIntervalDay({window})
        GROUP BY person_id
    )
)
SELECT
    s.period AS period,
    q.source AS source,
    count() AS opened,
    countIf(q.submitted) AS submitted,
    round(100 * submitted / opened, 1) AS submitted_of_opened_pct,
    round(100 * submitted / any(t.started), 1) AS submitted_of_starters_pct
FROM starters AS s
INNER JOIN quick AS q ON q.person_id = s.person_id
INNER JOIN (SELECT period, count() AS started FROM starters GROUP BY period) AS t ON t.period = s.period
GROUP BY period, source
ORDER BY period DESC, opened DESC
```

### Guardrails in the production database

Run them with `php artisan agent:db --prod` (see the `querying-the-database`
skill). The database has no onboarding events, so these cohorts are every
signup by `created_at`, not starters: they include people who never reached
`promise` (the baseline had 1023 signups for 830 starters). Compare the rows by
segment, and don't put the database numbers in the same denominator as the
PostHog ones. Replace `{deploy_at}` and `{window}` as above.

**Week-4 activity**, by period and by what the user ended up with. A user is
active in week 4 when `last_active_at ≥ created_at + 21 days`. The query also
returns activity after day 7, the definition the 10% baseline uses, so the
decision rule compares like with like.

`last_active_at` only keeps the latest visit, so a `before` user, who is older
when you run this, has had longer to come back. The query drops users younger
than 28 days, but it cannot equalise follow-up. Use the PostHog query after this
one, which counts a visit in days 21–28 only, for the overall verdict, and this
one for the split by segment.

```sql
SELECT
    IF(u.created_at < '{deploy_at}', 'before', 'after') AS period,
    CASE
        WHEN u.onboarded_at IS NULL THEN 'not onboarded'
        WHEN EXISTS (
            SELECT 1 FROM transactions t
            WHERE t.user_id = u.id AND t.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM label_transaction lt
                    JOIN labels l ON l.id = lt.label_id
                    WHERE lt.transaction_id = t.id AND l.source = 'estimate'
                )
        ) THEN 'real transactions'
        WHEN EXISTS (
            SELECT 1 FROM transactions t WHERE t.user_id = u.id AND t.deleted_at IS NULL
        ) THEN 'estimates only'
        ELSE 'no transactions'
    END AS segment,
    COUNT(*) AS users,
    ROUND(100 * SUM(u.last_active_at >= u.created_at + INTERVAL 7 DAY) / COUNT(*), 1) AS active_after_day_7_pct,
    SUM(u.last_active_at >= u.created_at + INTERVAL 21 DAY) AS active_week_4,
    ROUND(100 * SUM(u.last_active_at >= u.created_at + INTERVAL 21 DAY) / COUNT(*), 1) AS active_week_4_pct
FROM users u
WHERE u.created_at >= '{deploy_at}' - INTERVAL {window} DAY
    AND u.created_at < '{deploy_at}' + INTERVAL {window} DAY
    -- only users who have had the chance to be active in week 4
    AND u.created_at < NOW() - INTERVAL 28 DAY
GROUP BY period, segment WITH ROLLUP
ORDER BY period DESC, segment
```

The rows with a null `segment` are the totals per period. The segment is read
today, so a user who typed estimates and later imported a file shows up as
`real transactions`. That is the point of the last query.

**Week-4 activity in PostHog** (HogQL), with the same follow-up for both
cohorts: starters with a `$pageview` between day 21 and day 28 after their start.

```sql
WITH starters AS (
    SELECT
        person_id,
        min(timestamp) AS first_seen,
        if(first_seen < toDateTime('{deploy_at}'), 'before', 'after') AS period
    FROM events
    WHERE event = 'onboarding_step_viewed'
        AND timestamp >= toDateTime('{deploy_at}') - toIntervalDay({window} + 30)
    GROUP BY person_id
    HAVING countIf(properties.step = 'promise') > 0
        AND first_seen >= toDateTime('{deploy_at}') - toIntervalDay({window})
        AND first_seen < toDateTime('{deploy_at}') + toIntervalDay({window})
        -- only starters whose week 4 is over
        AND first_seen < now() - toIntervalDay(28)
),
visits AS (
    SELECT person_id, groupArray(timestamp) AS seen_at
    FROM events
    WHERE event = '$pageview'
        AND timestamp >= toDateTime('{deploy_at}') - toIntervalDay({window} - 21)
        AND person_id IN (SELECT person_id FROM starters)
    GROUP BY person_id
)
SELECT
    s.period AS period,
    count() AS started,
    countIf(arrayExists(
        t -> t >= s.first_seen + toIntervalDay(21) AND t < s.first_seen + toIntervalDay(28),
        v.seen_at
    )) AS active_week_4,
    round(100 * active_week_4 / started, 1) AS active_week_4_pct
FROM starters AS s
LEFT JOIN visits AS v ON v.person_id = s.person_id
GROUP BY period
ORDER BY period DESC
```

**Estimates followed by real movements within 30 days.** Users of the `after`
cohort who finished onboarding with estimates and nothing real, and how many
added a transaction without the estimate label within 30 days of signing up.
Users younger than 30 days are left out.

```sql
SELECT
    COUNT(*) AS estimate_only_users,
    SUM(EXISTS (
        SELECT 1 FROM transactions t
        WHERE t.user_id = u.id AND t.deleted_at IS NULL
            AND t.created_at < u.created_at + INTERVAL 30 DAY
            AND NOT EXISTS (
                SELECT 1 FROM label_transaction lt
                JOIN labels l ON l.id = lt.label_id
                WHERE lt.transaction_id = t.id AND l.source = 'estimate'
            )
    )) AS with_real_movements_30d
FROM users u
WHERE u.created_at >= '{deploy_at}'
    AND u.created_at < '{deploy_at}' + INTERVAL {window} DAY
    AND u.created_at < NOW() - INTERVAL 30 DAY
    AND u.onboarded_at IS NOT NULL
    -- estimates at the end of onboarding
    AND EXISTS (
        SELECT 1 FROM transactions t
        JOIN label_transaction lt ON lt.transaction_id = t.id
        JOIN labels l ON l.id = lt.label_id
        WHERE t.user_id = u.id AND t.deleted_at IS NULL AND l.source = 'estimate'
            AND t.created_at <= u.onboarded_at
    )
    -- and nothing real yet
    AND NOT EXISTS (
        SELECT 1 FROM transactions t
        WHERE t.user_id = u.id AND t.created_at <= u.onboarded_at
            AND NOT EXISTS (
                SELECT 1 FROM label_transaction lt
                JOIN labels l ON l.id = lt.label_id
                WHERE lt.transaction_id = t.id AND l.source = 'estimate'
            )
    )
```

Estimate transactions are dated the first of last month, so filter on
`t.created_at` (when it was added), never on `transaction_date`.

---

## When to run

About 470 people start onboarding a month. Detecting a move from 11% to 20% at
80% power and α=0.05 (two-sided) needs about **250 starters per period**.

- **First read: deploy + 5 weeks**, on the PostHog metrics. Run with
  `{window} = 28` so every starter of the `after` cohort has had at least 7 days
  to finish onboarding, like the baseline did. That is ~440 starters per period,
  enough.
- **Full 35-day comparison: deploy + 6 weeks**, with `{window} = 35`.
- **Retention guardrail: deploy + 9 weeks** (5 weeks of cohort plus 4 of
  follow-up), with `{window} = 35`. Week-4 activity needs every user of the
  cohort to be at least 28 days old; the queries drop the ones who are not.
- **Estimates followed by real movements: deploy + 10 weeks**, so the last users
  of the cohort have had their 30 days. Read earlier, the query leaves them out.

Use exclusive cohorts with full follow-up. Never count an immature cohort as
churned: a user who signed up 10 days ago has not had the chance to be active in
week 4. If you read early, shrink `{window}` until the cohort is mature, don't
read the whole window.

---

## Decision rule

- **Keep** when the primary metric reaches **≥ 20%**, the lift over `before` is
  significant (the 95% interval of `after − before` excludes zero) and the
  guardrails hold: desktop file-import completion does not clearly drop below
  the `before` cohort's, and week-4 activity of the `after` starters is not
  clearly below the `before` starters'. A retention delta inside the noise is a
  pass.
- **Investigate** when the primary moves but the activity of estimate-only users
  is **≤ 10%**, the baseline of users onboarded without transactions. Compare
  like with like: that 10% is activity after day 7, so read
  `active_after_day_7_pct` of the `estimates only` row. The reveal is landing,
  but estimates are not turning into use.
- **Judgement call** when the lift is significant but short of 20% (say 14–19%):
  the path works, less than hoped. Keep it if the guardrails hold, and look at
  the quick-expenses-by-source split for the weakest entry point.
- **Revisit** when the primary does not move: the path is there, but users are
  not taking it. Look at the quick-expenses-by-source split for which entry
  point fails.

---

## Results

Fill in once each read is done. Keep the query runs (links or the raw output)
next to the numbers.

**Deploy marker**: `{deploy_at}` = … · **Read on**: … · **`{window}`**: …

| Metric                                              | Baseline             | Before | After | Δ (95% CI) | Verdict |
| --------------------------------------------------- | -------------------- | ------ | ----- | ---------- | ------- |
| Starters (desktop / mobile / tablet)                | 830 (457 / 362 / 11) |        |       |            |         |
| **Spending reveal / starters** (primary)            | 11%                  |        |       |            |         |
| — real                                              | 11%                  |        |       |            |         |
| — estimate-only                                     | —                    |        |       |            |         |
| Quick expenses submitted / starters — `upload`      | —                    |        |       |            |         |
| Quick expenses submitted / starters — `default`     | —                    |        |       |            |         |
| Quick expenses submitted / starters — `reveal`      | —                    |        |       |            |         |
| Quick expenses submitted / starters — no source     | —                    |        |       |            |         |
| Onboarding completion                               | 49%                  |        |       |            |         |
| Desktop file-import completion (guardrail)          | 20%                  |        |       |            |         |
| Week-4 activity, all starters — PostHog (guardrail) | —                    |        |       |            |         |
| Week-4 activity by segment — database               | —                    |        |       |            |         |
| Active after day 7, estimate-only (guardrail)       | 10%¹                 | —      |       |            |         |
| Estimate-only → real movements in 30 days           | —                    | —      |       |            |         |

¹ Users onboarded without transactions, active after day 7 — the closest
baseline there is, measured the same way.

**Confounders seen during the windows**: …

**Decision**: …
