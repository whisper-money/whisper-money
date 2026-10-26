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
    - 'resources/js/lib/phone-bleed.ts'
---

# One transactions table, two data sources

Every list of transactions is `TransactionTable`
(`resources/js/components/transactions/transaction-table.tsx`). It owns the
rows with their menus and dialogs, selection, bulk actions, day headers, the
table or compact list, the skeleton and the "load more" footer. Where the rows
come from is a `TransactionTableSource` (`resources/js/types/transaction-table-source.ts`):

- `useServerTransactionSource`: `/transactions` (`pages/transactions/index.tsx`).
  Filters and sort live in the URL and every change asks the server again; "load
  more" fetches the next cursor page (and loads on scroll); day counts and totals
  come from the server (`dayTotals`), moved only by local edits. Only this source
  has `matchingFilters` ("all matching filters" bulk actions) and `refreshRows`.
- `useInMemoryTransactionSource`: `TransactionList`, the account, budget and
  savings-goal pages. Rows the page already holds, filtered, sorted and sliced in
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
- **How rows are fetched, filtered, sorted or paged**: the source. A new kind of
  list implements `TransactionTableSource` rather than growing the table.

## Edge to edge on a phone

Below md the compact list (and its skeleton and `topRow`) runs edge to edge,
like design A: `PHONE_BLEED` (`lib/phone-bleed.ts`) pulls it out of the page's
24px side padding with `-mx-6`, so it assumes the table sits straight in a
`p-6` page wrapper, as on /transactions and the account, budget and savings
goal pages. A table inside a card, a dialog or anything padded otherwise passes
`framedOnPhone` (the monthly savings goal view does). Edge to edge, a list
never scrolls inside its own `maxHeight` box on a phone: with no gutter left,
every swipe would scroll the list instead of the page.

## Day totals follow the server

Both sources total a day the same way: each currency summed over the day, then
converted once (`summarizeDay` here, `TransactionDayTotals` on the server).
Never convert row by row and add up: the rounding drifts a cent from the
server's figure (`TransactionsMultiCurrencyDayTotalsTest`).

## React Compiler

`DataTable`, `useGroupedRowVirtualizer` and the compact list inside
`ResponsiveTransactionTable` opt out (`'use no memo'`): they read the TanStack
table and virtualizer, stable objects that change underneath. Hand table state
to them as React state (`sorting`, `rowSelection`), as `TransactionTable` does,
not only through the table instance.

`TransactionTable` itself is compiled, so it never hands the TanStack table to
the page: a toolbar reading it during render would be cached on a reference
that never changes. Give the page what it needs ready-made instead, as the
`columnPicker` element.
