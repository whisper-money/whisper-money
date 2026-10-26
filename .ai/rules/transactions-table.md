---
paths:
    - 'resources/js/pages/transactions/index.tsx'
    - 'resources/js/components/transactions/transaction-list.tsx'
    - 'resources/js/components/transactions/transaction-columns.tsx'
    - 'resources/js/components/transactions/transaction-table-row.tsx'
    - 'resources/js/components/transactions/transaction-row-dialogs.tsx'
    - 'resources/js/hooks/use-transaction-row-editing.ts'
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
- `getTransactionRowActions()` (`resources/js/lib/transaction-row-actions.ts`):
  the contents of both row menus. Add an action there, never inline.

So a new row action goes into `TransactionRowActionHandlers`, the hook and,
if it opens a dialog, `TransactionRowDialogs`; both tables pick it up.

Still duplicated in both files until the remaining PRs land: the delete
confirmation and the bulk handlers (category, labels, delete, re-evaluate),
and the table shell (sorting, filters, load more). Anything added there has to
be wired in **both** places; grep for the symbol you just added.

Bitten before the split: split transactions were wired in `TransactionList`
only, so the row menu on `/transactions` threw `onSplit is not a function`.
