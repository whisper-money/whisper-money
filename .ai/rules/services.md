---
paths:
  - 'app/Services/Cashflow*.php'
  - app/Services/TransactionCsvExporter.php
---

# Services

## Cashflow figures: SQL aggregates, split parents hydrated
Cashflow figures (summary, sankey, trend, breakdown, forMonths, the expense split) come from CashflowAggregateQuery: unsplit rows are summed in SQL; split parents are still hydrated and expanded by effectiveTransactions(), so line conversion, the residue on the last line and the owner's share stay exact. Groups merge in first-seen (transaction_date, id) order, compared as bytes. Budgets' label tracking needs the tracked label ids passed to the query (CashflowBudgetReserveService::trackedLabelIds()); stand-ins only carry those labels. Any change here must keep tests/Feature/CashflowEquivalenceTest.php and its golden file passing unchanged.

## CSV export: one row per split line, counts are rows, encrypted cells empty
Split parts are real transactions and export as rows; a transaction still split the old way (TransactionSplit lines, legacy since 2026-11) writes one row per line (amount and category from the line, the rest from the parent) with upstream's header unchanged, so a re-import behaves like upstream's. Under a category/label filter only matching lines are exported; under a cashflow block filter lines are narrowed with CashflowBlockQuery::postingFilter(), the predicate the analysis drawer uses. GET api/transactions/count, X-Export-Rows and X-Encrypted-Descriptions count exported rows and must agree with the stream. Client-side encrypted fields (description_iv, notes_iv, an account's name_iv) are written empty, never ciphertext. Owner decision, 2026-10-25.
