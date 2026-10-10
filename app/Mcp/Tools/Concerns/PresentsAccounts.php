<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\AccountType;
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
            ...$this->presentCreditCardStatement($account, $user),
        ];
    }

    /**
     * A credit card's statement dates and the next payment they estimate, for
     * users who have the feature. Reads the `creditCardDetail` relation, so
     * eager-load it when presenting several accounts.
     *
     * @return array<string, mixed>
     */
    private function presentCreditCardStatement(Account $account, User $user): array
    {
        $statements = app(CreditCardStatementService::class);

        if ($account->type !== AccountType::CreditCard || ! $statements->isAvailableTo($user)) {
            return [];
        }

        return $statements->present($account, $user);
    }
}
