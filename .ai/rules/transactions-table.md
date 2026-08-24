---
paths:
  - 'resources/js/pages/transactions/index.tsx'
  - 'resources/js/components/transactions/transaction-list.tsx'
  - 'resources/js/components/transactions/transaction-columns.tsx'
---

# The transactions table exists twice

`pages/transactions/index.tsx` does **not** use `TransactionList`. It keeps its
own copy of the whole table: its own `TransactionRowComponent`, its own context
menu, its own dialogs, its own bulk-delete handlers. `TransactionList` is what
the account-detail and budget-detail pages use.

So anything added to a row has to be wired in **both** places, and the shared
`createTransactionColumns()` options are only half the job:

1. the options passed to `createTransactionColumns()` (both call sites),
2. the props of each page's own `TransactionRowComponent`, for the right-click menu,
3. any dialog the action opens, mounted in each page's JSX,
4. `EditTransactionDialog`, wired separately in each.

Bitten while adding split transactions: the feature was wired in
`TransactionList` only, so it worked on the account and budget pages while the
row menu on `/transactions` threw `onSplit is not a function` — the one screen
everybody actually uses.

Row **actions** are the exception, and the reason they are: the two menus render
from `getTransactionRowActions()` (`resources/js/lib/transaction-row-actions.ts`)
so their contents cannot drift. Add an action there, not inline in either menu.

Untangling the duplication is worth its own PR. Until then, grep for the symbol
you just added and make sure it appears in both files.
