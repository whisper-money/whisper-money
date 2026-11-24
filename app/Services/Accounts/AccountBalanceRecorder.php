<?php

namespace App\Services\Accounts;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Rules\StorableDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording a balance snapshot on an account, shared by the create_balance
 * MCP tool, `POST /api/v1/accounts/{account}/balances` and the bulk write a
 * broker import and the "Import balances" drawer make. One snapshot per day:
 * recording another for the same date replaces it.
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

        return self::noBalanceErrors($account, $key);
    }

    /**
     * Why the account has no balance to write at all, keyed by $key: a credit
     * card has a credit limit instead. Unlike unwritableErrors(), a connected
     * account passes, for the web surfaces that let the user write its history.
     *
     * @return array<string, string>
     */
    public static function noBalanceErrors(Account $account, string $key): array
    {
        if (! $account->type->hasBalance()) {
            return [$key => AccountType::NO_BALANCE_MESSAGE];
        }

        return [];
    }

    /**
     * Write the snapshot for $date (the owner's today when null), replacing the one
     * already there. A null $investedAmount leaves the stored one alone.
     */
    public function record(Account $account, int $balance, ?string $date, ?int $investedAmount): AccountBalance
    {
        return AccountBalance::updateOrCreate(
            ['account_id' => $account->id, 'balance_date' => $date ?? $account->ownerToday()->toDateString()],
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
     * A day rewritten here is the caller's from then on, so it stops belonging
     * to the full import that wrote it: undoing that import leaves it alone.
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
        $rowsByDate = $this->bulkRows($account, $balances);
        $hasInvested = fn (array $row): bool => array_key_exists('invested_amount', $row);

        DB::transaction(function () use ($rowsByDate, $hasInvested): void {
            $updated = ['balance', 'derived', 'import_id', 'updated_at'];

            $this->upsertInChunks(array_filter($rowsByDate, fn (array $row): bool => ! $hasInvested($row)), $updated);
            $this->upsertInChunks(array_filter($rowsByDate, $hasInvested), [...$updated, 'invested_amount']);
        });

        return count($rowsByDate);
    }

    /**
     * One row per day, keyed by the day, ready for an upsert.
     *
     * @param  list<array{balance_date: string, balance: int, invested_amount?: int|null}>  $balances
     * @return array<string, array<string, mixed>>
     */
    private function bulkRows(Account $account, array $balances): array
    {
        $keepsInvested = $account->type->supportsInvestedAmount();
        $now = now();
        $rowsByDate = [];

        foreach ($balances as $balance) {
            // The `date` rule takes more than Y-m-d, so the day is read the
            // way the database would store it, and two spellings of one day
            // are one day.
            $date = Carbon::parse($balance['balance_date'])->toDateString();
            $investedAmount = $keepsInvested ? ($balance['invested_amount'] ?? null) : null;

            $rowsByDate[$date] = [
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'balance_date' => $date,
                'balance' => (int) $balance['balance'],
                // The caller's own figure, so the backwards balance walk stops
                // treating this day as its own to recalculate.
                'derived' => false,
                'import_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
                ...$investedAmount !== null ? ['invested_amount' => (int) $investedAmount] : [],
            ];
        }

        return $rowsByDate;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<string>  $updated
     */
    private function upsertInChunks(array $rows, array $updated): void
    {
        foreach (array_chunk(array_values($rows), 1000) as $chunk) {
            AccountBalance::query()->upsert($chunk, ['account_id', 'balance_date'], $updated);
        }
    }
}
