---
paths:
    - 'resources/js/pages/transactions/index.tsx'
    - 'resources/js/components/transactions/transaction-list.tsx'
    - 'resources/js/components/transactions/transaction-columns.tsx'
    - 'resources/js/components/transactions/transaction-table-row.tsx'
    - 'resources/js/components/transactions/transaction-row-dialogs.tsx'
    - 'resources/js/hooks/use-transaction-row-editing.ts'
    - 'resources/js/hooks/use-transaction-bulk-actions.ts'
    - 'resources/js/components/transactions/transaction-delete-dialogs.tsx'
    - 'resources/js/hooks/use-in-memory-transaction-source.ts'
    - 'resources/js/hooks/use-server-transaction-source.ts'
---

# The transactions table exists twice (being unified)

`pages/transactions/index.tsx` does **not** use `TransactionList`, which is what
the account, budget and savings-goal pages use. The two are being merged into
one table over several PRs. What a row does is already shared:

- `useTransactionRowEditing` (`resources/js/hooks/use-transaction-row-editing.ts`):
  the transaction each row dialog is open for, the row action handlers
  (`rowActions`), the in-place row update, single-row re-evaluate, the
  automatize toast, and the labels the dialogs can create.
- `TransactionRowDialogs`: the edit, duplicate, split, unsplit and automate
  dialogs, mounted once per table.
- `TransactionTableRow`: the desktop row and its right-click menu.
- `useTransactionBulkActions` (`resources/js/hooks/use-transaction-bulk-actions.ts`):
  bulk category, labels, re-evaluate and delete, the single-row delete, and
  what `BulkActionsBar` takes (`barProps`). "All matching filters" exists only
  when the table passes `matchingFilters` (the server-paged `/transactions`).
- `TransactionDeleteDialogs`: the delete confirmations; `/transactions` makes
  the user type the count for a bulk delete (`confirmBulkByTyping`).
- `getTransactionRowActions()` (`resources/js/lib/transaction-row-actions.ts`):
  the contents of both row menus. Add an action there, never inline.

So a new row action goes into `TransactionRowActionHandlers`, the hook and,
if it opens a dialog, `TransactionRowDialogs`; both tables pick it up.

Where the rows come from is a `TransactionTableSource`
(`resources/js/types/transaction-table-source.ts`), one per kind of page:

- `useServerTransactionSource`: `/transactions`. Filters and sort live in the
  URL and every change asks the server again; "load more" fetches the next
  cursor page; day counts and totals come from the server (`dayTotals`).
- `useInMemoryTransactionSource`: the account, budget and savings-goal pages.
  Rows filtered, sorted and paged in the browser; day totals computed here.

Both total a day the same way: each currency summed over the day, then
converted once (`summarizeDay` / `TransactionDayTotals`). Never convert row by
row and add up: the rounding drifts a cent from the server's figure.

Still duplicated in both files until the last PR lands: the table shell (the
toolbar, the table, the footer, the dialogs and the bulk bar mounted around
the source). Anything added there has to be wired in **both** places; grep for
the symbol you just added.

Bitten before the split: split transactions were wired in `TransactionList`
only, so the row menu on `/transactions` threw `onSplit is not a function`.
