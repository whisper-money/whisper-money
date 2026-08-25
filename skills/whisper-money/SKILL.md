---
name: whisper-money
description: Work with the user's finances through the Whisper Money connector — spending, cashflow, net worth, budgets, categories, labels, automation rules and transaction splits. Use whenever the user asks about their money, their accounts, their budgets or their transactions, or asks to categorize, label, split, record or clean up transactions.
---

# Whisper Money

The connector's own instructions cover the rules (minor units, spaces, what a
budget or a split is). This skill covers how to get real work done with it.

## Before calling anything

- Ids are opaque. Get them from `list_accounts`, `list_categories`,
  `list_labels`, `list_budgets`, `list_automation_rules` — never guess one, and
  reuse them for the rest of the conversation instead of listing again.
- `search_transactions` has no paging: it returns at most 200 rows. Narrow with
  `from`/`to` and loop over periods rather than asking for "everything".
- Only mention spaces if the user does. Everything defaults to the personal
  space; call `list_spaces` when they say "shared", "household", "our".

## Recipes

**"How did I do this month?"** — `get_cashflow` for the month gives income,
expense, savings and the comparison with the previous period. Add
`spending_by_category` for the same range to say *where* it went, then drill
into the biggest root with `parent_category_id`.

**"What am I paying every month?"** — `search_transactions` over the last 3-6
months, then group by creditor and spacing yourself: same merchant, similar
amount, roughly 28-31 days apart. There is no subscriptions tool.

**"Categorize what's pending"** — search a date range, keep the rows whose
`category` is null, and set them with `categorize_transaction` (works on bank
transactions too). When the same merchant shows up repeatedly, stop doing it by
hand: `create_automation_rule` for future ones, then `apply_automation_rule`
for the history — it previews by default, so show the user the match count and
the sample before calling it again with `dry_run: false`.

**"This charge was for two things"** — `split_transaction` with parts that add
up to the original and share its sign. The parts replace it everywhere; to undo
it, `merge_transaction_splits` with any part.

**"Am I overspending?"** — `list_budgets` already reports allocated, spent and
remaining for the period in progress. Say `current_period: null` means no
period covers today, and treat `spent_amount` as provisional while
`processing_historical` is true.

**"Track my X spending"** — `create_budget` over the categories that matter.
Period length, start day, rollover and tracked categories are frozen after
creation, so get them right the first time: confirm them with the user before
creating, because changing them later means deleting and recreating the budget.

## What bites

- Bank transactions cannot be edited or deleted — only categorized, labelled or
  split. Reach for `categorize_transaction` / `label_transaction`, not
  `update_transaction`.
- `create_transaction` never dedups. Only add what the bank will not sync, or
  the user ends up with the charge twice.
- Balances (`create_balance`) work on manual accounts only; a connected
  account's balances come from the bank.
- Ask first before anything that destroys data: `delete_budget` (takes the
  spending history with it), `merge_transaction_splits` (loses the categories,
  labels and notes on every part), `delete_category` with
  `strategy: "cascade"`, and any bulk `dry_run: false` run.
- Amounts inside an automation rule's `rules_json` are in major units. Every
  other amount in the connector is in cents.
