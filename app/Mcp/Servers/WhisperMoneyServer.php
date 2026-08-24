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
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
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

- All amounts are integers in minor units (cents). Divide by 100 for a display value.
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
- To find recurring charges (subscriptions), use `search_transactions` and group
  the results by merchant and cadence yourself.

Write tools (create_transaction, update_transaction, delete_transaction,
categorize_transaction, label_transaction, create_balance, apply_automation_rule
and full CRUD for budgets, categories, labels and automation rules) require a
read & write token; a read-only token can analyse data but never change it.
Manual transactions can be created on any account, bank-connected ones included
— a sync never removes them.
Bank/imported transactions themselves are protected: only manually-created ones
can be edited or deleted, though you can categorize and label any transaction.
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
