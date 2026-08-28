# Per-currency decimal precision — feasibility assessment

Assessed 2026-08-28 against `currency-decimals-research` (base `6861010c`). Production
numbers read live via `php artisan agent:db --prod`. **No production code changed.**

## The current model

Every money value in the app is a signed integer in minor units at a **hardcoded scale of
2 decimals**. There is no per-currency scale anywhere: not in the schema, not in
`config/currencies.php`, not in the formatters. Confirmed. That single assumption is the
whole problem, and it produces two different failures that are worth separating because
their cost differs by an order of magnitude:

- **Precision loss (storage).** Currencies needing *more* than 2 decimals — BTC (8), KWD /
  BHD / TND (3) — cannot represent the real holding. Data is destroyed on write.
- **Cosmetic noise (display only).** Currencies needing *fewer* than 2 — JPY, CLP, PYG,
  COP — are stored fine and merely render a meaningless `,00`. Nothing is lost.

The second is much larger in user count and much cheaper to fix. The rest of this
assessment leans on that split.

## Corrections to the scouting

| Claim | Verdict |
|---|---|
| ~50 `*100`/`/100` in `app/`, ~71 in `resources/js` | 50 PHP, 66 JS (excl. tests). Of those, **29 PHP and 25 JS are app money**; 17 PHP and 32 JS are percentages/progress/geometry; 4 PHP and 9 JS are Stripe/subscription pricing (2dp fiat by definition, out of scope). Real conversion surface is **54 sites, not ~121**. |
| `savings_goals.archived_saved_amount` is bigint | **It is `int`** (max 2.147e9 ≈ 21.4M major units). Already tight; any rescale must widen it first. |
| `amount-display.tsx` ~31 call sites | 24 files, **70 usages**. 20 of them already pass explicit `0` fraction digits for compact chart labels. |
| `amount-input.tsx` ~19 call sites | 15 files, **29 usages**. |
| CSV import **and export** round-trips | **Import only.** `routes/web.php` has no money export route; no `fputcsv`/CSV response outside the stats mailer. Halves that line item. |
| `Intl` already knows the right digits | Confirmed and stronger than stated — CLDR gives **COP 0 digits** (ISO 4217 says 2), matching what Colombian users expect. Verified in Node: JPY/CLP/PYG/COP/ISK/VND/HUF → 0; KWD/BHD/TND → 3; BTC/ETH → 2 + literal code. |
| MCP tools are blast radius | Barely. `CreateTransaction` takes `integer` minor units and `PresentsTransactions` returns them raw — **scale-transparent**, only the word "cents" in the description is wrong. The one real coupling is `CreateAutomationRule`, which documents JsonLogic `amount` in *major* units. |

### What the scouting missed, and it matters most

**Only three tables carry a currency at all**: `accounts.currency_code`,
`transactions.currency_code`, `users.currency_code` — all `varchar(3)`.

These money columns have **no currency column** and resolve it by join or by convention:

| Column | Currency comes from |
|---|---|
| `account_balances.balance`, `.invested_amount` | join `accounts` |
| `real_estate_details.purchase_price`, `loan_details.original_amount` | join `accounts` |
| `budget_transactions.amount`, `budget_periods.allocated_amount`, `.carried_over_amount` | `users.currency_code` (primary) |
| `savings_goals.target_amount`, `.initial_amount`, `.archived_saved_amount` | `users.currency_code` (primary) |

So "add a `decimals` key to the config" is the easy 5%. The expensive 95% of a per-currency
exponent is **making the currency reachable from every money column** — a join or a
denormalised column on five tables that do not have one today.

Two consequences that shrink the problem a lot:

1. `users.currency_code` is constrained to `allows_primary` currencies, and **BTC is
   `allows_primary => false`**. Budgets and savings goals therefore *can never be in BTC*.
   The high-precision problem is confined to `accounts`, `transactions`, `account_balances`,
   `real_estate_details`, `loan_details`.
2. `currency_code` is `varchar(3)`, which blocks every 4-character crypto ticker (USDT,
   USDC, DOGE). Any genuinely crypto-general solution needs a column widening the scouting
   did not price.

## Production reality

Accounts (not soft-deleted) in currencies whose correct scale is not 2:

| Currency | Correct digits | Accounts | Users | Transactions | Balance rows | Failure mode |
|---|---|---|---|---|---|---|
| COP | 0 | 480 | 334 | 8,740 | 2,466 | display only |
| CLP | 0 | 174 | 125 | 6,572 | 901 | display only |
| PYG | 0 | 33 | 30 | 659 | 348 | display only |
| JPY | 0 | 1 | 1 | 395 | 118 | display only |
| **BTC** | **8** | **8** | **7** | **1** | **9** | **precision loss** |
| **KWD** | **3** | **5** | **1** | **0** | **5** | **precision loss** |
| BHD, TND | 3 | 0 | 0 | 0 | 0 | — |

Primary-currency users: COP 334, CLP 127, PYG 28, KWD 1, BTC 0 (not permitted). Savings
goals owned by a non-2dp-primary user: **0**. Budgets: **2**.

The BTC data is almost nonexistent: **one transaction with `amount = 2`** (0.02 BTC) and
**nine balance rows, largest 80** (0.80 BTC). Rescaling it is a 23-row `UPDATE`.

**Two numbers decide the storage question:**

Largest absolute value per column, against `bigint` max 9.223e18 and JS
`Number.MAX_SAFE_INTEGER` 9.007e15:

| Column | Max abs | bigint headroom |
|---|---|---|
| `transactions.amount` | 2,018,235,173,753,138,400 (2.02e18) | **4.6×** |
| `budget_periods.carried_over_amount` | 1,000,000,000,001,094,939 | 9.2× |
| `budget_periods.allocated_amount` | 999,999,999,991,000,000 | 9.2× |
| `account_balances.balance` | 460,332,104,102,226,300 | 20× |
| everything else | ≤ 2.75e10 | ≥ 3e8 × |

These extremes are junk rows (someone typed a wall of nines into a budget), but they are
live data and a naive `UPDATE … SET amount = amount * N` hits them.

Rows that **overflow bigint** at a global rescale:

| Rescale | transactions | account_balances | budget_periods |
|---|---|---|---|
| ×10 (2→3dp) | **1** | 0 | 0 |
| ×100 (2→4dp) | 7 | 0 | 0 |
| ×10⁴ (2→6dp) | 18 | 51 | 6 |
| ×10⁶ (2→8dp) | 44 | 53 | 6 |

Rows that **exceed `Number.MAX_SAFE_INTEGER`**, i.e. silently lose precision the moment
Inertia serialises them (both columns are cast to `'integer'`, so they ship as JSON
numbers, not strings):

| Scale | transactions | account_balances | budget_periods |
|---|---|---|---|
| **today** | **9** | **51** | **6** |
| ×10³ | 49 | — | — |
| ×10⁶ | 236 | 248 | — |

**Sixty-six rows are already past the JS safe-integer boundary today.** That is a live
precision bug independent of this work, and it is a hard ceiling on any global rescale.

## Options, costed

### (A) Per-currency exponent — the scale of a stored value depends on its currency

**Mechanics.** `decimals` key in `config/currencies.php`; a `scale($code)` accessor on each
side; a migration rescaling only rows whose currency's exponent moves.

**Killed as specified by production data.** The canonical version stores COP at 0 decimals.
But COP minor-unit values are *not* multiples of 100: **1,874 of 8,740 transactions (21%)
and 1,542 of 2,466 balance rows (63%)** have non-zero centavos, plus CLP 61/91 and PYG
7/112. Scaling those down truncates real user data and changes every aggregate they feed.
Display rounding is per-value and harmless; storage rounding is not.

**So the only safe form of (A) is asymmetric:** raise the scale where more precision is
needed, never lower it. Which is option (C).

**Full-fat cost if pursued anyway.** 54 conversion sites, plus currency resolution on the
five tables that lack a currency column, plus every SQL aggregate over raw minor units
(`Transaction::OWNED_AMOUNT_SQL`, `CashflowSummaryService`, `AccountMetricsService`, the
three analytics controllers) becoming scale-dependent, plus `CurrencyConversionService`
(floats in major units — each caller must divide by its own 10^d), plus ~86 test files.
Rounding actually *improves*: `round(amount * ownership_percentage / 100)` at a higher
scale has smaller error. **Estimate: 1–2 weeks, high migration risk, no reversibility once
mixed scales exist in one column.**

### (B) One global higher scale for everything

**Dead on arrival.** ×10 already overflows a live row. 2→6dp overflows 75 rows across three
tables; 2→8dp overflows 97 and pushes 484 rows past `MAX_SAFE_INTEGER` on the client. It
also requires all 54 conversion sites to change simultaneously in one deploy, invalidates
every Dexie cache, and buys nothing for the 0-decimal currencies. The only way to make it
work is to first migrate every money column to `decimal`/string and drop the JSON-number
contract — a far bigger project than the one being assessed.

Recording it as rejected with the numbers is the useful output here.

### (C) Keep 2dp for fiat, special-case the high-precision currencies

**Mechanics.** Same `decimals` key, but only currencies with `decimals > 2` get a storage
rescale: BTC 2→8 (×10⁶), KWD/BHD/TND 2→3 (×10). 0-decimal currencies get **display only**,
no migration, no truncation. Everything else stays untouched at 2.

**Migration size: 23 rows.** BTC — 1 transaction, 9 balance rows, 8 accounts' detail rows;
KWD — 5 balance rows, 0 transactions. Zero overflow risk (max BTC value is 80; max KWD
35,135). Fully reversible: divide back, and BTC's currently-truncated values are already at
2dp so the round-trip is exact.

**Files.** The `decimals` key, `CurrencyOptions` exposing it, one PHP accessor, one TS
accessor, `Money.php`, `currency.ts`, `amount-display.tsx`, `amount-input.tsx`, the three
crypto sync services (`Coinbase`, `Binance`, `Bitpanda`), `Transaction::filter`, one Dexie
version bump, one migration, ~6 test files. **Estimate: 3–4 days, low risk.**

## Display and input: how many sites actually change

`decimals` in `config/currencies.php` reaches the client for free —
`HandleInertiaRequests:115` already shares `currencies.profile` / `currencies.accounts`
built from `CurrencyOptions`, so adding the field to `formatOptions()` is a one-line change
and every page has it.

| Surface | Usages | Needs touching |
|---|---|---|
| `formatCurrency` in `utils/currency.ts` | — | **1.** Drop `= 2` defaults to `undefined` and Intl supplies the right digits per currency. Single highest-leverage edit in the whole study. |
| `<AmountDisplay>` | 70 in 24 files | **1** (its two `= 2` defaults). The 20 usages passing explicit `0` for chart labels keep working — that is a deliberate compact style, not a currency fact. |
| Direct `formatCurrency(...)` callers | 44 in 22 files | **~0.** They pass no fraction digits, so they inherit. 9 are subscription pricing and are correct as-is. |
| `<AmountInput>` | 29 in 15 files | **1**, but the real work is inside it: its own `formatCurrency` (`min/max: 2`), `parseInputValue` (`Math.round(parsed * 100)`), `handleFocus` (`.toFixed(2)`), `evaluateMathExpression` (`/100`, `*100`), and `placeholder = '0.00'`. Five hardcoded 2s in one file, then all 29 sites inherit. |
| `Money::format` (PHP) | 10 callers | **0.** All are Stripe/Discord/stats reporting in fiat. Parameterise `Money::format` anyway (2 lines) for consistency. |

**Answer to question 2: roughly five files genuinely change; ~140 call sites inherit.** The
plumbing really is half-built. `getCurrencySymbol`'s 10-entry map and `Money.php`'s symbol
`match` are separate, pre-existing gaps — both are already worse than
`Intl.NumberFormat(...).formatToParts()`, which `amount-input.tsx` uses correctly.

## Data integrity

- **Already-truncated BTC is gone.** 0.02 and 0.80 BTC are what the DB holds; ×10⁶ makes
  them 0.02000000 and 0.80000000. The migration must not *corrupt* them, and it does not —
  but it cannot recover what was never stored. Those 7 users need re-entry, which for 8
  accounts is a support email, not a data-repair job.
- **0-decimal currencies must not be rescaled.** 21% of COP transactions and 63% of COP
  balance rows carry non-zero centavos. This is the single most important integrity finding.
- **Balance history / budget snapshots.** `account_balances` is a per-date snapshot series,
  so a rescale must hit every historical row for the account, not just the latest — 9 rows
  for BTC, 5 for KWD. `budget_periods` and `savings_goals` are unreachable in BTC
  (`allows_primary => false`) and hold 0 goals / 2 budgets for non-2dp-primary users.
- **Dexie cache.** `dexie-db.ts` is at `version(10)` and caches `transactions` with raw
  integer amounts plus a `sync_metadata` cursor. A rescale needs `version(11)` clearing
  `transactions` and the cursor so the client re-syncs. One transaction row affected in
  production; the version bump is a few lines and there is precedent (versions 7 and 8 exist
  purely to reset state).
- **CSV import.** `file-parser.ts:844/852/970` and `import-balances-drawer.tsx:393/399` do
  `Math.round(x * 100)`. Each needs the target account's scale, which the drawer already
  knows. No export path exists, so no round-trip to break.
- **Rule-engine parity.** `rule-engine.ts:127` and `AutomationRuleService.php:320` both do
  `amount / 100` and must change identically or `rule-engine-parity.test.ts` goes red — a
  feature, not a cost. Note that automation-rule *thresholds* are stored in major units in
  the rule JSON, so an existing "amount > 100" rule keeps meaning the same thing.
- **`bun run dry`.** The scale accessor must exist once per language. Copying it into
  `Money.php` and the sync services trips a required check.

## The smallest useful increment

Two independent slices. **Slice 1 has no migration at all**, which is the finding worth
acting on.

**Slice 1 — stop overriding Intl (display only, ~1 day, zero migration risk).**
Change the `= 2` defaults in `currency.ts` and `amount-display.tsx` to `undefined`, and
parameterise `amount-input.tsx`. This immediately fixes **688 accounts held by up to 490 users, and
16,366 transactions** — every COP, CLP, PYG and JPY amount stops showing a meaningless
`,00` — and makes KWD render its three digits. It does not fix BTC storage, but it does
stop BTC showing a fake 2-decimal precision. Highest value-to-risk ratio in the study by a
wide margin, and it does not depend on any decision below.

**Slice 2 — BTC at 8 decimals (~2–3 days, 23 rows).**
Add `decimals` to the config with an override for BTC (8) and KWD/BHD/TND (3); rescale
those rows; bump Dexie; fix the three crypto sync services and `Transaction::filter`.

**Does "BTC at 8 decimals, everything else unchanged" deliver most of the value? For the
forum poster, yes — it is the entire complaint, and it costs 23 rows.** But it serves 7
users, while slice 1 serves 490 for less work. Ship slice 1 first regardless of what is
decided about storage.

## Recommendation

**Option (C), delivered as slice 1 then slice 2. Reject (A) as specified and (B) outright.**

| Option | Files | Migration | Test surface | Risk | Effort |
|---|---|---|---|---|---|
| Slice 1 (display) | ~5 | none | `currency.test.ts`, `amount-input.test.tsx` | very low | ~1 day |
| Slice 2 (C, BTC+KWD storage) | ~15 | 23 rows, reversible | + ~6 PHP test files | low | 2–3 days |
| (A) full per-currency exponent | ~60 + 5 schema changes | every money row, irreversible | ~86 PHP files + 4 JS | high | 1–2 weeks |
| (B) global rescale | all 54 sites at once | every money row, **overflows** | ~86 PHP files + 4 JS | unacceptable | — |

Concretely: `decimals` goes in `config/currencies.php` next to `allows_primary`; the value
is `Intl`'s answer for every ISO currency (so the key exists mainly to carry BTC's 8 and to
give PHP the number JS gets from CLDR); storage scale is `min(2, decimals)` raised only
where `decimals > 2`. Storage scale and display scale stay two separate concepts — conflating
them is precisely what makes (A) destroy COP data.

## Risks I would not accept

1. **Rescaling 0-decimal currencies down.** 1,874 COP transactions and 1,542 COP balance
   rows have non-zero centavos. Non-negotiable: display at 0 digits, store at 2.
2. **Any global rescale.** ×10 overflows a live `transactions.amount` row today. There is no
   version of (B) that is safe without first moving off JSON-number money.
3. **A migration without the `savings_goals.archived_saved_amount` widening.** It is `int`,
   not bigint. Any rescale touching it silently clamps at 2.147e9.
4. **Mixed scales inside one column without a currency reachable from that row.** Five
   money tables have no `currency_code`. Introducing per-row scales there before adding the
   column is unrecoverable: you cannot tell 8-decimal rows from 2-decimal rows after the
   fact.
5. **Shipping a crypto-general story on `varchar(3)`.** BTC fits; USDT and USDC do not. Either
   widen the column in the same change or state plainly that only 3-character tickers are
   supported.
6. **Presenting slice 2 as "crypto support".** The 7 affected users' existing BTC values are
   already truncated and cannot be recovered by any migration. They must be told to re-enter
   their holdings.

## Follow-ups this surfaced (out of scope, worth filing)

- **66 money rows already exceed `Number.MAX_SAFE_INTEGER`** (9 transactions, 51 balances,
  6 budget periods) and are cast to `'integer'`, so the browser receives corrupted values
  today. Independent bug, independent fix (validation ceiling on amount input).
- `budget_periods` holds `999999999991000000` — input validation has no upper bound.
- `Money.php`'s 5-entry symbol `match` and `currency.ts`'s 10-entry `getCurrencySymbol` map
  cover 15 of 36 configured currencies. `Intl.NumberFormat(...).formatToParts()` — already
  used correctly in `amount-input.tsx` — replaces both.
