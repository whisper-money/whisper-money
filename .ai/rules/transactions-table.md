---
paths:
  - resources/js/pages/transactions/index.tsx
  - resources/js/components/transactions/transaction-list.tsx
  - resources/js/components/transactions/transaction-table.tsx
  - resources/js/components/transactions/transaction-columns.tsx
  - resources/js/components/transactions/transaction-table-row.tsx
  - resources/js/components/transactions/transaction-row-dialogs.tsx
  - resources/js/components/transactions/transaction-delete-dialogs.tsx
  - resources/js/components/transactions/transaction-remove-labels-dialog.tsx
  - resources/js/components/transactions/transaction-filter-summary.tsx
  - resources/js/components/transactions/transaction-list-row.tsx
  - resources/js/components/transactions/transaction-day-header.tsx
  - resources/js/components/transactions/transaction-list-skeleton.tsx
  - resources/js/components/transactions/responsive-transaction-table.tsx
  - resources/js/hooks/use-transaction-row-editing.ts
  - resources/js/hooks/use-transaction-bulk-actions.ts
  - resources/js/hooks/use-in-memory-transaction-source.ts
  - resources/js/hooks/use-server-transaction-source.ts
  - resources/js/types/transaction-table-source.ts
  - resources/js/lib/compact-list-style.ts
  - resources/js/lib/transaction-day-groups.ts
  - resources/js/components/transactions/split-transaction-dialog.tsx
  - resources/js/components/transactions/unsplit-transaction-dialog.tsx
  - resources/js/components/transactions/split-origin-popover.tsx
  - resources/js/components/transactions/split-parts-list.tsx
  - resources/js/lib/transaction-splits.ts
  - app/Services/TransactionDayTotals.php
  - app/Services/TransactionFilterSummary.php
---

# Transactions table

## One table, two sources
Every list of transactions is TransactionTable: rows with their menus and dialogs, selection, bulk actions, day headers, table or compact list, skeleton, "load more". Where the rows come from is a TransactionTableSource: useServerTransactionSource (/transactions: filters in the URL, cursor pages, day figures from the server, moved only by local edits; only it has matchingFilters and refreshRows) or useInMemoryTransactionSource (TransactionList on the account, budget and savings goal pages: rows the page holds, filtered, ordered and sliced in the browser). Both answer countMatching(filters, signal), the "See N transactions" of the phone's sheet: rows the list would show, never the export's count, which is per split line. The server one asks api/transactions/listed-count, which reads no total and no rate, so a count on every pause never reaches the rates provider. Each source declares `facets`, the filters it applies, and the desktop menu and the phone's sheet offer those and no others: a chip must never claim a filter the rows do not obey. The server source applies them all. The in-memory one applies all but the cashflow block, which budgets decide; its label filter ORs with the category filter as on the server, and AI-only reads category_source. A new facet goes in the source's filter, its count and its day total, or stays out of its `facets`. A page-only difference is a prop, not a branch on the source. Rows are DecryptedTransaction here. A row action goes in getTransactionRowActions() and useTransactionRowEditing, never inline in a menu; Split and "Merge the split back" are row actions like the rest: Split only with the TransactionSplitting flag on and never on a part, a zero amount or a row with old lines; Merge on a part, which has no Duplicate or Delete. On a phone the compact list has no row menu, so the edit dialog's "More actions" offers the same actions, Edit aside.

## Split parts are rows; old split lines are read-only
A split replaces the transaction with parts: ordinary rows with split_parent_id and split_siblings, each with its own category, labels and notes, counted and totalled as rows by every list, filter, day total and export. The original is soft-deleted and listed nowhere. A part shows the origin mark (split-origin-popover.tsx), which lists its siblings and merges them back; its amount, account, date and currency are locked, and it cannot be deleted or split again. After a split or a merge the table clears its selection and reloads through its source.

A transaction split the old way (TransactionSplit lines in `splits`, with is_split and split_count) is legacy: one row, shown read-only with its lines until transactions:convert-legacy-splits turns it into parts, and nothing in the UI writes lines. While such rows exist the old reading stays: under a filter that names a category, a category type, a label or a cashflow block the row adds only the lines that filter keeps (owner decision D3); TransactionDayTotals, TransactionFilterSummary and the CSV must agree on which lines count, so the narrowing lives once, in TransactionCsvExporter::constrainLines() and postings(), and TransactionDayTotals::narrowedSplitTransactions() is how a figure reads it. createRowTotalAmount() is the client's twin for local edits and the in-memory source: change them together.

## Day totals and summaries: one bounded attempt at the provider
Each currency is summed per day, then converted once: never row by row, the rounding drifts a cent from the server's figure. Amounts in another currency go through ExchangeRateService::convertManyOrNull(), never convert() or the default path: it reads stored rates in one query, asks the provider one date at a time, stops at the first failure or once the budget is spent, and leaves the rest null. A day with no rate shows no total rather than a figure that skipped it, and a summary with such a day has no total or average. The default path's walk, its cache and its TTLs stay as they are, so nothing a failed attempt learned reaches them. A summary pays one attempt for the total and one for the trend. Anything new that shows a converted figure while someone waits takes the same path.

## Keeping the server source honest
Filters are applied by a visit, and what the visit brings replaces the rows. A "load more" still on its way when the filters change or the rows are replaced is aborted and its response discarded, so a page of the old list never lands in the new one. A local edit moves the day headers at once and the summary card a moment later: onTransactionsChanged is refreshSummary, a debounced async partial reload of filterSummary alone, skipped without a filter or while a visit is on its way, cancelled by whatever replaces the rows. Async, so it never cancels a visit the user started; a reload targets the URL still in the bar, so one landing after a visit would put the old summary and URL on the new list. Do not make it reload the rows: that would undo the local edits' place in the list.

## Always newest first, always by day
There is no sort: transaction_date desc then id desc (sortedForList(), compareNewestFirst in memory), under a header per day, with no Date column. The server ignores a sort query param, and IndexTransactionRequest::listCursor() reads a bookmarked cursor from a sort that is gone as the first page, so old URLs still load. Do not bring back sortable headings, a sort menu or a flat list without that being decided.

## Filters
Desktop is the chips toolbar, applied at once; a phone is the search, Filters and the full-screen sheet, whose edits stay a draft until "See N transactions". A facet lives in lib/transaction-filter-facets.ts, FACET_MENU and the sheet, in both or neither. The cashflow block only exists over a whole date range and drops with its dates (withoutDatelessCashflowBlock). The category filter reaches subcategories and split lines through the fork's own lib/transaction-category-filter.ts, on both sources. New filter fields default when read: a saved filter or a page in the browser's history may predate them, and filtersFingerprint() adds a field only when set, because stored overrides key on it.

## Compact list and React Compiler
Below md the list is design D's frame, 16px from the screen's edges (PHONE_FRAME and PHONE_INSET in lib/compact-list-style.ts). They pull it into a p-6 page, so a table in a card or a dialog passes framedOnPhone. There the list never scrolls inside its own maxHeight box. Colours and sizes come from that file: check a change at 390px, light and dark. DataTable and the compact list in ResponsiveTransactionTable opt out of the compiler ('use no memo') because they read stable objects that change underneath; hand them state as React state. TransactionTable is compiled, so it never hands the TanStack table to a page: give the page what it needs ready-made, as columnPicker.
