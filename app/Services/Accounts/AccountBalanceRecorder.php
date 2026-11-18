<?php

namespace App\Services\Accounts;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Rules\StorableDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording a balance snapshot on an account, shared by the create_balance
 * MCP tool, `POST /api/v1/accounts/{account}/balances` and the bulk write a
 * broker import makes. One snapshot per day: recording another for the same
 * date replaces it.
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
    public static function unwritableErrors(Account $account, string $key): array
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

    /**
     * Write many snapshots at once, with the same rules as record(): each
     * replaces the one already on its day, and a null invested amount leaves
     * the stored one alone. An invested amount is only kept on an account type
     * that tracks one; on any other it is dropped, as if it were null. When a
     * day comes twice, the last one wins.
     *
     * One upsert per kind of row (with and without an invested amount, since
     * an upsert updates the same columns on every row), inside one
     * transaction, so a failed write leaves the account as it was.
     *
     * @param  list<array{balance_date: string, balance: int, invested_amount?: int|null}>  $balances
     * @return int How many days were written.
     */
    public function recordMany(Account $account, array $balances): int
    {
        $keepsInvested = $account->type->supportsInvestedAmount();
        $now = now();
        $byDate = [];

        foreach ($balances as $balance) {
            $date = substr($balance['balance_date'], 0, 10);
            $investedAmount = $keepsInvested ? ($balance['invested_amount'] ?? null) : null;

            $byDate[$date] = [
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'balance_date' => $date,
                'balance' => (int) $balance['balance'],
                // The caller's own figure, so the backwards balance walk stops
                // treating this day as its own to recalculate.
                'derived' => false,
                'created_at' => $now,
                'updated_at' => $now,
                ...$investedAmount !== null ? ['invested_amount' => (int) $investedAmount] : [],
            ];
        }

        $withInvested = array_values(array_filter($byDate, fn (array $row): bool => array_key_exists('invested_amount', $row)));
        $withoutInvested = array_values(array_filter($byDate, fn (array $row): bool => ! array_key_exists('invested_amount', $row)));

        DB::transaction(function () use ($withInvested, $withoutInvested): void {
            $updated = ['balance', 'derived', 'updated_at'];

            foreach (array_chunk($withoutInvested, 1000) as $chunk) {
                AccountBalance::query()->upsert($chunk, ['account_id', 'balance_date'], $updated);
            }

            foreach (array_chunk($withInvested, 1000) as $chunk) {
                AccountBalance::query()->upsert($chunk, ['account_id', 'balance_date'], [...$updated, 'invested_amount']);
            }
        });

        return count($byDate);
    }
}
