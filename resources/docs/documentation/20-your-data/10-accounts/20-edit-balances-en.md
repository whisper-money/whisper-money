# Edit balances

Correct the balance history of an account you keep by hand: change a figure, fix a date, or delete a record that should not be there.

## Two ways in

**Update balance** on the account page writes today's balance. It is the quick one: you have just checked your bank and want the number to match.

**See balances** in the account's `⋯` menu opens the whole history, one record per date, and lets you change or delete any of them.

![The balance history modal, listing one balance per date with edit and delete actions on each row](/docs/documentation/balances-modal.png)

Both are named after what the account holds: _market values_ on an investment or retirement account, _owed amount_ on a loan.

A connected account has neither. Its balances arrive from the bank on every sync, so there is nothing here to correct — fix it at the bank, or the next sync puts it back.

## Editing a record

Open a record to change its **date** and its **amount**. Investment, retirement and savings accounts also carry an **invested amount**, which is what you put in rather than what it is worth now.

There is one balance per date. Moving a record onto a date that already has one replaces that date's balance, so the history never ends up with two answers for the same day.

## Deleting a record

Deleting removes that date from the history, and the charts are redrawn from the records that are left. Delete a record because it was wrong, not to tidy the list: a sparser history makes the account's chart less accurate, not smaller.

## What this changes

Balances are what the net worth chart and the account's own chart are drawn from. Transactions are not: adding a transaction by hand can move the balance with it, but editing an old balance does not create or change any transaction.

## FAQ

### I fixed the balance and net worth still looks wrong.

Check the other accounts. An account you have never given a balance to has nothing to contribute, so it reads as zero until you set one. [Accounts](/documentation/accounts) covers which account types count towards net worth and which do not.

### Can I edit balances that came from a bank?

No. Those accounts do not offer the option, because the next sync would overwrite the edit.
