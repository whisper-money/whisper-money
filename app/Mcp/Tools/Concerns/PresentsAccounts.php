<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Account;

trait PresentsAccounts
{
    /**
     * The account row shape every account tool returns. `is_connected` is the
     * field that decides what the agent may do next: a connected account's
     * balances, currency and bank come from the sync.
     *
     * @return array<string, mixed>
     */
    protected function presentAccount(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'currency' => $account->currency_code,
            'bank' => $account->bank?->name,
            'is_connected' => $account->isConnected(),
            'ownership_percentage' => $account->ownership_percentage,
        ];
    }
}
