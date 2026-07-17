<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Whisper Money')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
Read-only access to the authenticated user's Whisper Money finance data, for
analysing spending, cashflow and net worth.

- All amounts are integers in minor units (cents). Divide by 100 for a display value.
- Data is organised into "spaces" (the personal space and any shared spaces).
  Transaction and account tools accept an optional `space` id and default to the
  personal space; call `list_spaces` to discover ids. The cashflow, net-worth and
  spending tools cover the user's whole account.
- To find recurring charges (subscriptions), use `search_transactions` and group
  the results by merchant and cadence yourself.
MARKDOWN)]
class WhisperMoneyServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        SearchTransactions::class,
        SpendingByCategory::class,
        GetCashflow::class,
        GetNetWorth::class,
        ListAccounts::class,
        ListCategories::class,
        ListSpaces::class,
    ];
}
