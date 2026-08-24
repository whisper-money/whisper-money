# Categories

Categories explain what each transaction means. Pick the right category and your reports become easier to trust.

{{TOC}}

## Quick start

1. Decide whether the transaction is income, expense, transfer, savings, or investment.
2. Use transfer categories for money moving between your own accounts.
3. Review uncategorized transactions often.
4. Create automation rules for repeated merchants.

> Not sure what to pick? Start with the type. You can rename the category later.

## Category map

```mermaid
flowchart TD
    transaction[Transaction] --> category[Category]
    category --> cashflow[Cashflow]
    category --> budgets[Budgets]
    category --> reports[Reports]
```

Examples:

- Groceries → Expense → spending reports and budgets.
- Salary → Income → income and cashflow reports.
- Checking to savings → Transfer → avoids double-counting.

## Category types

Each category has one type.

<div class="cards-wrapper">

<div class="card">
### Expense

Use this for money leaving your finances.

Examples:

- Groceries
- Rent
- Transport
- Subscriptions
- Taxes

</div>

<div class="card">
### Income

Use this for money coming into your finances.

Examples:

- Salary
- Freelance income
- Refunds
- Dividends
- Interest

</div>

<div class="card">
### Transfer

Use this when money moves between accounts you own.

Examples:

- Checking to savings
- Bank account to credit card
- Bank account to investment account

</div>

<div class="card">
### Savings

Use this when money leaves your day-to-day finances to be kept.

Examples:

- Monthly transfer to an emergency fund
- Money set aside for a goal

Savings categories are not spending. They feed the Saved & Invested card on the
Cashflow page.

</div>

<div class="card">
### Investment

Use this when money leaves your day-to-day finances to be invested.

Examples:

- Broker deposit
- Pension contribution
- Index fund purchase

Like savings, investments are counted as money set aside rather than as
spending.

</div>
</div>

## Transfers and cashflow direction

Transfer categories can be shown or hidden in cashflow.

Options:

- **Do not show**: hide the transfer from cashflow.
- **Show as cash inflow**: show the transfer as money coming in.
- **Show as cash outflow**: show the transfer as money going out.

For most account-to-account movement, **Do not show** is the safest choice.

The direction is yours to pick only on transfer categories. Savings and
investment categories always count as money going out, and income and expense
categories are counted by their type rather than by a direction.

## Uncategorized transactions

Imported or synced transactions may start without a category.

Try this routine:

1. Open uncategorized transactions.
2. Assign the obvious ones first.
3. Leave confusing ones for later if needed.
4. Create automation rules for repeated merchants or descriptions.

## Who set the category

Every categorized transaction records how it got its category:

- **You**, by picking one.
- **An automation rule** that matched it.
- **Whisper Money**, when you have turned on AI categorization.
- **Your bank**, when the connection supplied one.

The transaction filters can narrow the list by any of these, which is the fastest
way to review what was assigned automatically before you trust a month's
reports.

## AI categorization

Whisper Money can suggest categories for transactions you have not categorized
yet. It is off until you turn it on, because it means sending the transaction
description to an AI provider.

What to know:

- You choose whether to enable it, and you can turn it off again at any time.
- It only fills categories that are empty. It never overwrites one you picked.
- Anything it sets is marked as set by Whisper Money, so you can find and check
  it later.
- Correcting one of its categories can be turned into an automation rule, so the
  same merchant is handled without AI next time.

## Changing a category

Changing a transaction category updates reports that include that transaction.

This can affect:

- Spending totals
- Budget progress
- Income totals
- Cashflow

Changing the category itself, such as its name or type, affects all transactions using that category.

## FAQ

### What if I choose the wrong category?

You can change it later. Reports update after the transaction is recategorized.

### Should credit card payments be expenses?

Usually no. If you already track the card purchases, the payment is money moving between your own accounts. Use a transfer category.

### How many categories should I create?

Start small. Too many categories make reports harder to read. Add more only when you need more detail.

## Good category habits

- Keep names short and clear.
- Avoid duplicate categories for the same kind of spending.
- Use transfer categories for movement between your own accounts.
- Review uncategorized transactions before trusting monthly reports.
- Automate repeated merchants and descriptions.
