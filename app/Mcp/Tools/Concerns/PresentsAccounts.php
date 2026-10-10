<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Account;
use App\Models\User;
use App\Services\CreditCards\CreditCardStatementService;
use Illuminate\Support\Arr;

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
            // Limit, statement dates, the next payment they estimate and the
            // credit in use, on a credit card for a user who has the feature.
            // The day-by-day series is for the app's chart, not the agent.
            // Reads `creditCardDetail`, so eager-load it when presenting
            // several accounts.
            ...Arr::except(app(CreditCardStatementService::class)->presentFor($account, $user), 'credit_card_usage.daily'),
        ];
    }
}
