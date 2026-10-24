# Budgets

Budgets help you plan spending and see how much room is left in a period.

{{TOC}}

## Quick start

1. Create a budget for an area you want to control.
2. Pick the category or label it should track.
3. Set the amount for the period.
4. Mark it **Fixed** or **Variable**.
5. Review progress during the month.
6. Adjust the budget when your real spending changes.

## Budget flow

```mermaid
flowchart TD
    %% diagram: budget-flow-en
    budget[Budget] --> target[Category or label]
    transactions[Transactions] --> target
    target --> progress[Progress]
    progress --> decision[Spend, pause, or adjust]
```

## What a budget tracks

A budget can track spending that matches a category or label.

Use a category when the spending has one clear meaning.

Use a label when the spending cuts across categories.

Example:

- Category budget: Groceries.
- Label budget: Vacation, Project, Business trip.

## Good budget examples

<div class="cards-wrapper">

<div class="card">
### Groceries

Good for everyday spending that happens often.

Review weekly.

</div>

<div class="card">
### Restaurants

Good for optional spending that can grow quickly.

Review mid-month.

</div>

<div class="card">
### Travel

Good for spending across many categories.

A label-based budget can work well here.

</div>

<div class="card">
### Subscriptions

Good for repeated expenses.

Automation rules can help keep these categorized.

</div>
</div>

## Reading budget progress

Budget progress compares matched transactions with the budget amount.

If progress is high early in the period, slow down or increase the budget if the plan was too low.

If progress is low, the budget may be generous or the period may not be finished.

## Budget periods

A budget repeats over a period you choose: **monthly**, **weekly**, **every two
weeks**, or **yearly**. Monthly suits most spending; weekly works well for
groceries; yearly fits things like insurance or taxes that arrive once.

When reviewing a budget, make sure you are looking at the right period.

### What happens at the end of a period

Each budget decides what to do with what is left over:

- **Reset**: the next period starts fresh at the budget amount. This is the usual
  choice for regular spending.
- **Carry over**: what you did not spend is added to the next period, and going
  over eats into it. Better for a budget you are saving up within, like a yearly
  travel budget.

## Expense type

Every budget has an **Expense Type**: **Fixed** or **Variable**. You pick it
when you create the budget, and you can change it whenever you edit it. New
budgets start as Variable.

- **Fixed**: the same amount every period, like rent or subscriptions. Mark as
  fixed what you cannot cut from one month to the next: rent or mortgage,
  subscriptions, insurance, phone and internet, loan payments, the gym.
- **Variable**: spending you can adjust, like groceries or restaurants.

The expense type does not change how the budget itself works. Progress, alerts
and what happens at the end of a period are the same for both. It only tells
the [Cashflow](/documentation/cashflow) page how to split your expenses.

## A budget for everything else

One budget can be marked as the catch-all. Instead of tracking a category or a
label, it absorbs every expense that no other budget is already tracking.

It answers the question a set of budgets normally cannot: how much am I spending
outside of what I planned for.

## Budgets on the Cashflow page

The [Cashflow](/documentation/cashflow) page reads your budgets in two ways.

### Fixed, variable and general expenses

The [Expenses card](/documentation/cashflow#expenses-fixed-variable-and-general)
splits your expenses for the period into three parts:

- **Fixed**: spending on the categories and labels a fixed budget tracks.
- **Variable**: spending on what a variable budget tracks.
- **General**: spending no budget tracks. The catch-all budget counts here too:
  it is, by definition, everything your other budgets leave out.

A transaction tracked by both a fixed and a variable budget counts once, as
fixed. The split looks at the budgets that had a period in the range you are
viewing, archived ones included, so archiving a budget does not move its past
spending into general.

### Reserved

The [Available card](/documentation/cashflow#available-and-free) shows what is left of your income after expenses, savings
and investments. Part of that money is still promised to budgets you have not
finished spending: that is the **Reserved** amount, and what remains is
**Free**.

Reserved adds up what is left in each budget period that is in progress today
and overlaps the view:

- A budget that is already over adds nothing. It never eats into the others.
- A period that has ended adds nothing, and neither does one that has not
  started yet. Looking at a past month, nothing is reserved.
- Only the budget amount counts. Anything carried over from earlier periods is
  left out.

Which budgets count depends on the view. The **Month** and **Quarter** views
count weekly, every-two-weeks and monthly budgets. The **Year** view counts
only yearly ones.

## Alerts

A budget can tell you when it needs attention:

- When a new transaction lands in it.
- When it gets close to its limit.
- When it goes over.

They arrive by email, and they are set per budget under Settings →
Notifications, where you can also set what new budgets start with. So you can
watch the two that matter and leave the rest quiet.

## Common mistakes

- Budgeting before transactions are categorized.
- Creating too many budgets at once.
- Mixing one-time purchases with normal monthly spending.
- Forgetting that labels can be better for projects or trips.

## FAQ

### Do budgets change my transactions?

No. Budgets read transactions. They do not change them.

### Should every category have a budget?

No. Budget only what you want to actively control.

### Do I have to mark every budget as fixed or variable?

No. Leave the ones you are unsure about as Variable. Change the few that cover
bills you cannot cut, like rent or subscriptions, to Fixed.

### Why is nothing reserved on the Cashflow page?

Either no budget period is in progress today, or every one of them is already
spent or over. A yearly budget only counts in the Year view, and a monthly one
only in the Month and Quarter views.

### Why does a budget look empty?

The matching transactions may be uncategorized, use a different label, or be in another period.
