---
paths:
    - 'resources/js/pages/transactions/index.tsx'
    - 'resources/js/components/transactions/transaction-list.tsx'
    - 'resources/js/components/transactions/transaction-table.tsx'
    - 'resources/js/components/transactions/transaction-columns.tsx'
    - 'resources/js/components/transactions/transaction-table-row.tsx'
    - 'resources/js/components/transactions/transaction-row-dialogs.tsx'
    - 'resources/js/components/transactions/transaction-delete-dialogs.tsx'
    - 'resources/js/hooks/use-transaction-row-editing.ts'
    - 'resources/js/hooks/use-transaction-bulk-actions.ts'
    - 'resources/js/hooks/use-in-memory-transaction-source.ts'
    - 'resources/js/hooks/use-server-transaction-source.ts'
    - 'resources/js/types/transaction-table-source.ts'
    - 'resources/js/components/transactions/responsive-transaction-table.tsx'
    - 'resources/js/lib/compact-list-style.ts'
    - 'resources/js/components/transactions/transaction-list-row.tsx'
    - 'resources/js/components/transactions/transaction-day-header.tsx'
    - 'resources/js/components/transactions/transaction-list-skeleton.tsx'
---

# One transactions table, two data sources

Every list of transactions is `TransactionTable`
(`resources/js/components/transactions/transaction-table.tsx`). It owns the
rows with their menus and dialogs, selection, bulk actions, day headers, the
table or compact list, the skeleton and the "load more" footer. Where the rows
come from is a `TransactionTableSource` (`resources/js/types/transaction-table-source.ts`):

- `useServerTransactionSource`: `/transactions` (`pages/transactions/index.tsx`).
  Filters live in the URL and every change asks the server again; "load
  more" fetches the next cursor page (and loads on scroll); day counts and totals
  come from the server (`dayTotals`), moved only by local edits. Only this source
  has `matchingFilters` ("all matching filters" bulk actions) and `refreshRows`.
- `useInMemoryTransactionSource`: `TransactionList`, the account, budget and
  savings-goal pages. Rows the page already holds, filtered, ordered and sliced in
  the browser, with day totals over every row the filters keep.

The two pages are thin wrappers: a source, plus what only they show, passed to
the table as props (`renderToolbar` for the actions menu, export and the
`columnPicker` it hands over; `topRow` for the AI consent banner, whose logic is
`useAiCategorizationConsent`; `footerActions` for "View in Transactions";
`isNewRow`, `hiddenLabelId`, `statementPayments`, `categorizingIds`, …).

## Where a change goes

- **A row action**: `getTransactionRowActions()` (`lib/transaction-row-actions.ts`)
  for both menus, `TransactionRowActionHandlers` and `useTransactionRowEditing`
  for the handler and, if it opens a dialog, `TransactionRowDialogs`. Never
  inline in a menu.
- **A bulk action or the delete confirmation**: `useTransactionBulkActions`
  (it returns the `barProps` for `BulkActionsBar`) and `TransactionDeleteDialogs`.
- **Anything every table does** (a column option, a footer control, selection):
  `TransactionTable`. A page-only difference is a prop, not a branch on which
  source it was handed.
- **How rows are fetched, filtered, ordered or paged**: the source. A new kind of
  list implements `TransactionTableSource` rather than growing the table.

## Always newest first, always by day

There is no sort: every list reads newest first (`transaction_date` desc, then
`id` desc, `sortedForList()` on the server, `compareNewestFirst` in memory) under
a header per day, and has no Date column (the day headers carry the date). The
server ignores a `sort` query param, so old bookmarks still load. Don't bring
back sortable headings, a sort menu or a flat list without that being decided.

## Framed 16px from the edges on a phone

Below md the compact list is framed as design D draws it: 16px from the
screen's edges, a 1px border, 8px top corners and no bottom border, since the
list runs on with the page (the last row draws the closing hairline). The
skeleton and `topRow` take the same inset. `PHONE_FRAME` / `PHONE_INSET`
(`lib/compact-list-style.ts`) pull it 8px into the page's 24px side padding
with `-mx-2`, so they assume the table sits straight in a `p-6` page wrapper,
as on /transactions and the account, budget and savings goal pages. A table
inside a card, a dialog or anything padded otherwise passes `framedOnPhone`
(the monthly savings goal view does) and keeps a plain rounded box. This
replaced the edge-to-edge list of #1209 on purpose: don't bring the bleed
back. On a phone the list never scrolls inside its own `maxHeight` box: with
only a 16px gutter left, nearly every swipe would scroll the list instead of
the page.

The list's colours and sizes are D's to the pixel (line height 1.4, the
`COMPACT_LIST_MUTED_TEXT` grey for the secondary line, notes and day totals);
check a change against the mockup at 390px, light and dark.

## Day totals follow the server

Both sources total a day the same way: each currency summed over the day, then
converted once (`summarizeDay` here, `TransactionDayTotals` on the server).
Never convert row by row and add up: the rounding drifts a cent from the
server's figure (`TransactionsMultiCurrencyDayTotalsTest`).

## React Compiler

`DataTable`, `useGroupedRowVirtualizer` and the compact list inside
`ResponsiveTransactionTable` opt out (`'use no memo'`): they read the TanStack
table and virtualizer, stable objects that change underneath. Hand table state
to them as React state (`rowSelection`), as `TransactionTable` does,
not only through the table instance.

`TransactionTable` itself is compiled, so it never hands the TanStack table to
the page: a toolbar reading it during render would be cached on a reference
that never changes. Give the page what it needs ready-made instead, as the
`columnPicker` element.
