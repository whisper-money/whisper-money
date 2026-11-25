<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\WidgetPreview;
use App\Mcp\Tools\AggregateTransactions;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAccount;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateDashboard;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateSavingsGoal;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\CreateWidget;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteBalance;
use App\Mcp\Tools\DeleteBudget;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetCreditCardUsage;
use App\Mcp\Tools\GetMonthlySavings;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\GetRunway;
use App\Mcp\Tools\GetWidget;
use App\Mcp\Tools\GetWidgetSdk;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\ListBalances;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListDashboards;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListRecurringSeries;
use App\Mcp\Tools\ListSavingsGoals;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\MergeTransactionSplits;
use App\Mcp\Tools\PlaceWidget;
use App\Mcp\Tools\RemoveWidget;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use App\Mcp\Tools\SpendingByLabel;
use App\Mcp\Tools\SplitTransaction;
use App\Mcp\Tools\UpdateAccount;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateBudget;
use App\Mcp\Tools\UpdateBudgetPeriod;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateSavingsGoal;
use App\Mcp\Tools\UpdateTransaction;
use App\Mcp\Tools\UpdateWidget;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Whisper Money')]
#[Version('1.9.0')]
#[Instructions(<<<'MARKDOWN'
Access to the authenticated user's Whisper Money finance data, for analysing
spending, cashflow and net worth — and, with write access, for editing that
data.

Whisper Money is bookkeeping only: no tool moves money, pays anyone or reaches a
bank. When the user asks to transfer, pay or send money, say plainly that this
app cannot do that — do not ask for a destination account as if it could — and
offer to record the transfer as a manual transaction once they have made it
themselves.

- All amounts are integers in minor units (cents). Divide by 100 for a display value.
- Data is organised into "spaces" (the personal space and any shared spaces).
  Transaction, account, category, label, budget, balance, automation-rule and savings goal
  tools accept an optional `space` id and default to the personal space; call
  `list_spaces` to discover ids.
  `get_cashflow`, `get_net_worth`, `spending_by_category`, `spending_by_label`,
  `get_credit_card_usage` and `get_monthly_savings` are the exception: they take no
  `space` and aggregate every space the user has (the user's own rows only), with no way
  to narrow them to one. Never present their figures next to a space-filtered total as if
  the two were comparable — the gap is the other spaces, not a discrepancy.
- Categories form a tree, and a child never disagrees with its parent: it inherits the
  parent's type and cashflow direction, and moving a category re-derives both and cascades
  them to everything below it. Deleting one with `strategy: "cascade"` also needs
  `confirm_cascade: true`, because it takes the whole subtree and uncategorizes its
  transactions.
- `list_automation_rules` shows the rules in evaluation order. Read it before writing a rule
  so you do not duplicate or contradict one that exists, and after writing to confirm what
  was stored. The `amount` variable inside `rules_json` is in major units, unlike every
  other amount in this API.
- Budgets are owner-scoped inside a space. `create_budget` creates the cadence and
  tracking configuration; `update_budget` changes the name, allocation, categories
  and labels. Tracking changes preserve closed-period history and recalculate the
  active period. Cadence, start day and rollover remain immutable. Historical
  assignment may still be processing after creation or a tracking change.
  Each budget also has an `expense_type`, `fixed` or `variable` (the default),
  which both tools accept and `update_budget` can change at any time; only the
  cashflow expense split reads it. A catch-all budget is always `variable`.
- For monthly budgets, allocation changes use the user's own date as the application
  date. A change on the first day includes the current period; after that it affects
  periods whose start date is on or after the application date, plus future periods.
- For a one-off month (an extraordinary expense), use `update_budget_period`: it sets
  the allocation of the single period that contains `date`, the one in progress or a
  future one up to 12 months ahead, and leaves every other period as it was. Periods
  report `is_one_off` and the `regular_allocated_amount` they go back to. A later
  allocation change through `update_budget` keeps one-off amounts and only moves their
  regular allocation; `update_budget_period` with `reset: true` puts a period back on
  the regular allocation.
- `get_cashflow`'s summary splits `expense` three ways by budget: `fixed_expense`
  is spending tracked by budgets marked fixed (rent, subscriptions),
  `variable_expense` by budgets marked variable (groceries, leisure), and
  `general_expense` the rest, which no budget tracks. Catch-all budgets do not
  count. Each posting (a transaction, one part of a split included, or one line
  of a transaction still split the old way) counts once, as fixed when budgets
  of both kinds track it, so a split can be partly fixed, partly variable.
  The three add up to `expense`, and any can be negative in a month of
  refunds. Both `current` and `previous` carry the split; only `current`
  carries `reserved` and `budgets` (each with its `expense_type`).
  Budgets are variable unless the user marked them fixed, so `fixed_expense: 0`
  may only mean none has been marked yet, not that there are no fixed costs.
- A savings goal is tracked through a label: its contributions are the
  transactions tagged with it. A `one_off` goal saves towards a total, optionally
  by a date, and `list_savings_goals` reports its `progress`. A `monthly` goal
  starts over every calendar month with a target that is either a fixed `amount`
  or an `income_rate`, a share of the average income of the three previous
  complete months, frozen when the month opens. Each month is judged against its
  own target: `monthly.history` lists every month since the goal was created
  with its `target`, `saved`, signed `difference` and `status` (`met`, `missed`,
  `in_progress`, `partial` or `archived`); nothing carries over between months,
  and a transaction tagged late recalculates the month it belongs to. A
  `partial` month (the goal was created in the month's last 5 days) and an
  `archived` one (the goal was archived during it) show what was saved but
  have no verdict: they are left out of `months_met`, `months_closed`, the
  streaks and `cumulative_difference`, so never report them as met or missed.
  A savings account can feed only one running monthly goal's auto-tag rule. A goal's kind is fixed, and
  editing a monthly target only changes the month in progress and later ones.
  A transaction counts towards a goal once it carries the goal's `label_id`
  (`label_transaction`). `create_savings_goal` can also add an automation rule
  that tags incoming transfers to one of the user's savings accounts
  (`auto_tag_account_id`), including the ones already there since the start of
  the month; the response reports the rule and how many it tagged. Archiving
  and deleting a goal happen in the app only.
- Recurring charges (subscriptions, bills, standing payments) are detected server-side
  on a schedule. Read them with `list_recurring_series` instead of grouping
  `search_transactions` results by merchant and cadence yourself. Expected amounts are
  medians and may be marked variable; `monthly_equivalent_amount` rescales any cadence
  to a month so series can be compared and summed. Totals are reported per currency in
  `monthly_expense_totals`; never add amounts from different currencies together.
- For anything forward-looking — "do I make it to payday", "what is left at the end of
  the month", "am I about to overdraw" — call `get_runway`. It already walks the balance
  through the expected charges and adds an estimate of everyday spending, so do not
  rebuild it from `list_recurring_series` or `get_cashflow`, which reports the past. Its
  `everyday_spending` block is an estimate and is null when there is too little history;
  when it is present, quote its figures rather than the exact ones, which ignore
  groceries and everything else that never repeats on a cadence.
- For totals over many transactions (spending per month, per label, per account,
  income against expenses over time), use `aggregate_transactions` instead of
  paging through `search_transactions`. Like `search_transactions` it reads one
  space, every member's transactions in it, and groups them by up to two
  dimensions, at most one of them a period, returning one bucket per group and
  currency: amounts in different currencies are never added together, so read
  each bucket's `currency`. Like the other analytics, amounts are the owner's
  share of the account, and an archived account stops counting from the day it
  was archived. One part of a split counts like any transaction, under its own
  category, type and labels. A transaction still split the old way counts by
  its lines: each line lands in its own category and type, a category or type
  filter keeps only the lines it matches, and a label keeps every line. Every
  type counts unless `types`
  narrows it, so pass `types: ["expense"]` for spending: otherwise transfers,
  savings and investments add in too. Grouped by a period, every period of the
  range comes back, zeros included; a request that would return more than 1000
  buckets is refused, so narrow the range or pick a longer period. A transaction
  with several labels counts once under each of them.
- `spending_by_label`, `get_credit_card_usage` and `get_monthly_savings` give
  the figures of the app's dashboard cards, across every space like
  `get_cashflow`. `spending_by_label` ranks the labels by expense over a range,
  each against the previous period; a transaction counts under every label it
  carries (one still split the old way with its expense lines only), and savings goal labels are
  left out. `get_credit_card_usage` gives each live card's `credit_card_usage`
  (see below) plus `daily`, the running `used` from `period_from` to today, in
  the card's own currency. `get_monthly_savings` adds up the running monthly
  goals' month in progress (`saved` against `target`, a partial first month
  left out) and gives `last_month`: how many goals met their target of how many
  were judged.
- A transaction that covered several things can be split into parts, each with
  its own category and labels: `split_transaction` replaces it with 2-20 parts
  whose amounts must add up to the original and all move money the same way.
  From then on the parts are what exists — the original is out of every list and
  every total, and only the parts are returned. Each part carries a
  `split_parent_id` shared with its siblings; pass any one of them to
  `merge_transaction_splits` to put the original back, which deletes the parts
  and everything set on them. A part cannot be split again, deleted on its own,
  or have its amount, date, account or other core fields changed; categorizing,
  labelling and editing its notes works as usual. A transaction still split
  the old way shows `is_split: true` and its lines under `splits`: it reads as
  before, but it cannot be split again, merged, or have its category or amount
  changed until it is converted to parts; its notes and labels still change.
- Custom dashboards: the user can build their own dashboards from widgets you
  write as React components (TSX), drawn with their live data. Dashboards and
  widgets belong to the user, not to a space, and a widget reads only the
  user's own data. They are enabled per account: when the dashboard tools are
  not listed, or one says the feature is not enabled for this account, tell
  the user so instead of retrying. To build one: call
  `get_widget_sdk` first and follow its `rules` — the code is checked against
  closed lists and anything not listed is refused. Then `create_widget` with a
  manifest (name, size, the API calls whose answers the widget reads, with
  `{{range.from}}`/`{{range.to}}` for the range the dashboard shows, and
  `frame`: the dashboard draws a card around every widget, so never draw one
  around yours; set `frame: "none"` only for a row of KPI tiles that draw
  their own cards, as the SDK's `rules` say) and the TSX, and `place_widget`
  it on a dashboard (`list_dashboards`, `create_dashboard`). A refused save answers with every error, each with a
  `path` (`manifest.data.x.params.y`, `source:12:5`) and a `message`: fix them
  all and save again. Write texts in the user's language, make it responsive
  with container variants and right in light and dark mode, show every amount
  with the SDK's `<Amount>` (minor units, with the row's currency) and hide
  figures in charts when `privacyMode` is on; a widget never touches the
  network. A widget
  that throws on the dashboard keeps that error: `get_widget` returns it as
  `last_error` with its revision, and `update_widget` saves the fix as a new
  revision. Clients that show MCP Apps draw a live preview of the widget under
  `create_widget`, `update_widget` and `get_widget`.

Write tools ... require a read & write Sanctum token; OAuth connections follow the current
WriteTool policy. A read-only Sanctum token can analyse data but never change it. Manual
transactions can be created on any account, bank-connected ones included — a sync never
removes them. Bank/imported transactions keep their core fields protected: only a
manually-created one can change its description, amount, date, currency, account or
counterparty names, and only a manually-created one can be deleted. One part of a split
keeps every core field, its date included, and cannot be deleted on its own: merge the
split first. Their notes and category are not locked — `update_transaction` writes those
on any transaction, split parts included — and you can label any transaction, and split
any accessible one with `split_transaction`. Balances can only be
recorded on non-connected accounts, since a connected account's balances come from the bank
and would be overwritten (`list_balances` reads any account's, `delete_balance` removes a
manual one recorded by mistake).
Accounts come in two kinds and the difference decides what can be written.
`create_account` opens a manual account of any of the eight types — a loan or a
property takes its own details (interest rate and term, purchase price and date)
and, given a balance, gets the monthly history in between generated for it, the
older part in the background. A bank-connected account cannot be created at all:
one is only born when the user goes through their bank's consent flow in the
Whisper Money app, so ask them to connect the bank there rather than trying to
build one.
`update_account` changes only the fields it is passed. On a manual account that
is everything: name, type, currency, bank, ownership share and the loan/property
details. On a connected account only the name and the ownership fields can
change — the currency is what its whole synced history is denominated in, the
bank comes from the connection, and the type can only move between the four
types that keep a transaction ledger (checking, credit_card, savings, others),
since the others would leave the sync nowhere to write. Neither tool archives,
hides or deletes an account; the user does that in the app. Retyping a loan or a
property to something else keeps its details (interest rate, purchase price) and
any mortgage link on file but stops using them, so confirm it with the user
first.
`ownership_percentage` below 100 means the user only owns that share of the
account, and the app counts only that slice of it in their figures — changing it
also reweighs the budgets that already counted the account.
When a credit card row carries `credit_card_statement`, it is an estimate of the
next charge (`is_final` false means the cycle is still open), computed from the
statement dates in `credit_card_detail`; it is not a transaction. Those dates are
one cycle, and later cycles repeat monthly or weekly from them, as
`statement_frequency` says.
`credit_card_usage` is how much of the card is in use: `used` adds up what is
still to be charged between `period_from` and today (the pending statement plus
the open cycle, or the calendar month when the card has no statement dates),
less the payments made on those statements (none is subtracted without
statement dates), never below 0. `available` is `limit` minus it, negative when
over the limit, null while `credit_limit` is not set. A credit card has no balance:
`create_account` refuses one for it and `create_balance` rejects the card.
MARKDOWN)]
class WhisperMoneyServer extends Server
{
    /**
     * Serve the whole catalogue on one page.
     *
     * `tools/list` is paginated and the framework default of 15 splits thirty
     * tools across two pages, so a client that does not follow `nextCursor`
     * silently loses everything past the fifteenth — half the write tools, and
     * whichever read tool a later addition happens to push over the edge. The
     * ceiling is 100, comfortably above the current count (52 with the custom
     * dashboard tools), and a larger discovery payload is a better trade than
     * tools nobody can see. The framework caps any page at
     * $maxPaginationLength (50 by default), so it is raised with it.
     *
     * @api Read by Laravel MCP's Server when it builds the tools/list page,
     *      never by application code.
     */
    public int $defaultPaginationLength = 100;

    /** @api Read by Laravel MCP's Server, see $defaultPaginationLength. */
    public int $maxPaginationLength = 100;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        // Read
        SearchTransactions::class,
        SpendingByCategory::class,
        SpendingByLabel::class,
        AggregateTransactions::class,
        GetCashflow::class,
        GetRunway::class,
        GetNetWorth::class,
        GetCreditCardUsage::class,
        GetMonthlySavings::class,
        ListAccounts::class,
        ListAutomationRules::class,
        ListBalances::class,
        ListBudgets::class,
        ListCategories::class,
        ListLabels::class,
        ListRecurringSeries::class,
        ListSavingsGoals::class,
        ListSpaces::class,
        ListDashboards::class,
        GetWidgetSdk::class,
        GetWidget::class,

        // Write
        CreateAccount::class,
        UpdateAccount::class,
        CreateTransaction::class,
        UpdateTransaction::class,
        DeleteTransaction::class,
        CategorizeTransaction::class,
        SplitTransaction::class,
        MergeTransactionSplits::class,
        LabelTransaction::class,
        CreateBalance::class,
        DeleteBalance::class,
        CreateCategory::class,
        UpdateCategory::class,
        DeleteCategory::class,
        CreateLabel::class,
        UpdateLabel::class,
        DeleteLabel::class,
        CreateAutomationRule::class,
        UpdateAutomationRule::class,
        DeleteAutomationRule::class,
        CreateBudget::class,
        UpdateBudget::class,
        UpdateBudgetPeriod::class,
        DeleteBudget::class,
        CreateSavingsGoal::class,
        UpdateSavingsGoal::class,
        CreateDashboard::class,
        CreateWidget::class,
        UpdateWidget::class,
        PlaceWidget::class,
        RemoveWidget::class,
    ];

    /** @var array<int, class-string<Server\Resource>> */
    protected array $resources = [
        WidgetPreview::class,
    ];
}
