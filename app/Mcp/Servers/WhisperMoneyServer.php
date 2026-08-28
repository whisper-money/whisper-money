<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ApplyAutomationRule;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteBudget;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\MergeTransactionSplits;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use App\Mcp\Tools\SplitTransaction;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateBudget;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateTransaction;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Whisper Money')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
Access to the authenticated user's Whisper Money finance data, for analysing
spending, cashflow and net worth — and, with write access, for editing that
data.

- All amounts are integers in the minor units of their own currency, and how many
  those are per major unit depends on the currency: 100 for EUR and USD, 1 for
  COP, CLP, PYG, JPY and PKR, 1000 for KWD, 100000000 for BTC. Read the row's
  `currency` before scaling one, and never assume cents.
- Data is organised into "spaces" (the personal space and any shared spaces).
  Transaction, account, category and label tools accept an optional `space` id and
  default to the personal space; call `list_spaces` to discover ids. The cashflow,
  net-worth, spending and budget tools cover the user's whole account.
- A budget is a per-period spending limit over the user's own categories and/or
  labels; tracking a parent category also tracks its children. `list_budgets`
  reports the period in progress, or `current_period: null` when no period covers
  today. `remaining_amount` is the allocated amount minus what was spent, matching
  the figure the app shows. Matching transactions are attached in the background,
  so treat `spent_amount` as provisional while `processing_historical` is true.
  A budget's period length, start day, rollover and tracked categories are fixed
  once created: to change those, delete the budget and create it again.
- An automation rule categorizes and labels transactions automatically. It only
  runs on transactions created after it, so applying one to the history already
  in the account is a separate, preview-first step: `list_automation_rules` for
  the ids, then `apply_automation_rule`, which reports the matches and changes
  nothing until it is called again with `dry_run: false`. Amounts inside a
  rule's `rules_json` are in MAJOR units, unlike every other amount here.
- A transaction that covered several things can be split into parts, each with
  its own category and labels: `split_transaction` replaces it with 2-20 parts
  whose amounts must add up to the original and all move money the same way.
  From then on the parts are what exists — the original is out of every list and
  every total, and only the parts are returned. Each part carries a
  `split_parent_id` shared with its siblings; pass any one of them to
  `merge_transaction_splits` to put the original back, which deletes the parts
  and everything set on them. A part cannot be split again, deleted on its own,
  or have its amount, date or account changed; categorizing and labelling it
  works as usual.
- To find recurring charges (subscriptions), use `search_transactions` and group
  the results by merchant and cadence yourself.

Write tools (create_transaction, update_transaction, delete_transaction,
split_transaction, merge_transaction_splits, categorize_transaction,
label_transaction, create_balance, apply_automation_rule and full CRUD for
budgets, categories, labels and automation rules) require a read & write token;
a read-only token can analyse data but never change it.
Manual transactions can be created on any account, bank-connected ones included
— a sync never removes them.
Bank/imported transactions themselves are protected: only manually-created ones
can be edited or deleted, though you can categorize, label and split any
transaction.
Balances can only be recorded on non-connected accounts, since a connected
account's balances come from the bank and would be overwritten.
MARKDOWN)]
class WhisperMoneyServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        // Read
        SearchTransactions::class,
        SpendingByCategory::class,
        GetCashflow::class,
        GetNetWorth::class,
        ListAccounts::class,
        ListCategories::class,
        ListLabels::class,
        ListBudgets::class,
        ListAutomationRules::class,
        ListSpaces::class,

        // Write
        CreateTransaction::class,
        UpdateTransaction::class,
        DeleteTransaction::class,
        SplitTransaction::class,
        MergeTransactionSplits::class,
        CategorizeTransaction::class,
        LabelTransaction::class,
        CreateBalance::class,
        CreateCategory::class,
        UpdateCategory::class,
        DeleteCategory::class,
        CreateLabel::class,
        UpdateLabel::class,
        DeleteLabel::class,
        CreateAutomationRule::class,
        UpdateAutomationRule::class,
        DeleteAutomationRule::class,
        ApplyAutomationRule::class,
        CreateBudget::class,
        UpdateBudget::class,
        DeleteBudget::class,
    ];
}
