<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Account;
use App\Models\User;
use App\Services\CreditCards\CreditCardStatementService;

trait PresentsAccounts
{
    /**
     * The account row shape every account tool returns. `is_connected` is the
     * field that decides what the agent may do next: a connected account's
     * balances, currency and bank come from the sync.
     *
     * @return array<string, mixed>
     */
    protected function presentAccount(Account $account, User $user): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'currency' => $account->currency_code,
            'bank' => $account->bank?->name,
            'is_connected' => $account->isConnected(),
            'ownership_percentage' => $account->ownership_percentage,
            // Statement dates and the next payment they estimate, on a credit
            // card for a user who has the feature. Reads `creditCardDetail`, so
            // eager-load it when presenting several accounts.
            ...app(CreditCardStatementService::class)->presentFor($account, $user),
        ];
    }
}
