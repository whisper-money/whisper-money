---
paths:
  - 'app/Services/Demo/**'
  - 'app/Console/Commands/ResetDemoAccountCommand.php'
---

# Seeded accounts (demo, press, app-store reviewers)

`demo:reset` seeds every shared/named account from one dataset shape: `name`,
`locale`, `currency`, `subscription_prefix`, `accounts`, `labels`, `rules`,
`budgets`, `transaction_templates`. The demo's own data lives in the command's
`DEMO_ACCOUNTS`/`DEMO_BUDGETS` consts plus the three `Demo*Provider` classes;
the press account's lives in `PressDataset`. Add a third account by adding a
dataset, never by copying the command — `bun run dry` is a required check.

## Never delete the user row on a reseed
`findOrCreateDemoUser` finds-or-creates and re-applies the profile. A
journalist's OAuth connection and every MCP token hang off the user id, so
recreating the row breaks every live connection in silence, with nothing in the
logs. `deleteExistingData` deliberately leaves `tokens` and `settings` alone.

## A budget's tracked category lives in a pivot
`Budget` has no fillable `category_id`. Passing one to `Budget::create()` is
silently dropped and the budget tracks nothing, so every period reads as
untouched ("Assigned 0 transactions to budgets"). Always
`$budget->categories()->sync([$id])`. `BudgetTransactionService` matches through
that pivot (or the label pivot), never a column.

## Category references stay canonical English
`CreateDefaultCategories` seeds Spanish names when `$user->locale === 'es'`, so
a dataset that hardcoded Spanish names would break the demo and vice versa.
Datasets name categories in English and the command resolves them through
`CreateDefaultCategories::localizedCategoryName()`. A name that does not
resolve makes `createMixedTransactions` skip the row silently — no error.

## Dates are derived from one `now()`
`DemoTransactionsProvider` takes `$endDate = Carbon::now()` and derives
`$startDate` from it. Calling `now()` twice puts start+12 months microseconds
past the end, and every monthly template loses its occurrence in the month in
progress — the current month ends up with no salary and no rent.

## Seeded accounts must stay out of metrics and mail
The subscription is fake, so its `stripe_id` prefix (`sub_demo_`, `sub_press_`,
`sub_e2e_`) is what `hasSeededSubscription()` and `cannotUseStripe()` read.
Any new report over users or subscriptions needs
`User::excludingSharedAccounts()`, and the reset command switches every e-mail
notification preference off.
