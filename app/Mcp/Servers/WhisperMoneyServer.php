<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use App\Mcp\Tools\UpdateAutomationRule;
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
  net-worth and spending tools cover the user's whole account.
- To find recurring charges (subscriptions), use `search_transactions` and group
  the results by merchant and cadence yourself.

Write tools (create_transaction, update_transaction, delete_transaction,
categorize_transaction, label_transaction, create_balance and full CRUD for
categories, labels and automation rules) require a read & write token; a
read-only token can analyse data but never change it. Bank-connected accounts
and bank/imported transactions are protected: you can only create, edit or
delete manual transactions and manual-account balances, but you can categorize
and label any transaction.
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
    ];
}
