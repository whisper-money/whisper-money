<?php

namespace App\Services\Accounts;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Rules\StorableDate;

/**
 * Recording a balance snapshot on an account, shared by the create_balance
 * MCP tool and `POST /api/v1/accounts/{account}/balances`. One snapshot per
 * day: recording another for the same date replaces it.
 */
class AccountBalanceRecorder
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'balance' => ['required', 'integer'],
            'balance_date' => ['sometimes', 'date', new StorableDate],
            'invested_amount' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * Why the account takes no manual snapshot, keyed by $key. Connected
     * accounts are refused: their balances come from the bank and any manual
     * snapshot would be overwritten by the next sync. Credit cards are refused
     * too: they have a credit limit instead of a balance.
     *
     * @return array<string, string>
     */
    public function unwritableErrors(Account $account, string $key): array
    {
        if ($account->isConnected()) {
            return [$key => 'That account is connected to a bank, so its balances come from the sync and a manual snapshot would be overwritten. Only non-connected (manual) accounts accept balances.'];
        }

        if (! $account->type->hasBalance()) {
            return [$key => AccountType::NO_BALANCE_MESSAGE];
        }

        return [];
    }

    /**
     * Write the snapshot for $date (today when null), replacing the one
     * already there. A null $investedAmount leaves the stored one alone.
     */
    public function record(Account $account, int $balance, ?string $date, ?int $investedAmount): AccountBalance
    {
        return AccountBalance::updateOrCreate(
            ['account_id' => $account->id, 'balance_date' => $date ?? now()->toDateString()],
            [
                'balance' => $balance,
                // The caller's own figure, so the backwards balance walk stops
                // treating this day as its own to recalculate.
                'derived' => false,
                ...$investedAmount !== null ? ['invested_amount' => $investedAmount] : [],
            ],
        );
    }
}
