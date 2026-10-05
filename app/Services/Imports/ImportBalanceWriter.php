<?php

namespace App\Services\Imports;

use App\Enums\ImportChunkKind;
use App\Models\AccountBalance;
use App\Models\Import;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the daily balances a full import carried, one row per account and
 * day, as balances the file gave rather than ones the app derived.
 *
 * An account the import created takes every balance the file had for it. One
 * of the user's own only takes the days it had no balance for: overwriting a
 * figure the user entered would be the one change undoing the import could
 * not put back.
 *
 * @phpstan-type BalanceRow array{account_key: string, date: string, balance: int}
 */
class ImportBalanceWriter
{
    public function write(Import $import, ImportAccountMap $accounts): void
    {
        $totals = ['total' => $import->stagedRowCount(ImportChunkKind::Balances), 'imported' => 0];

        $import->recordStats(['balances' => $totals]);

        while (($chunk = $import->nextChunk(ImportChunkKind::Balances)) !== null) {
            [$created, $preexisting] = $this->rowsFor($import, $accounts, $chunk->rows);

            DB::transaction(function () use ($import, $chunk, $created, $preexisting, &$totals): void {
                if ($created !== []) {
                    AccountBalance::query()->upsert($created, ['account_id', 'balance_date'], ['balance', 'derived', 'import_id', 'updated_at']);
                }

                $totals['imported'] += count($created) + ($preexisting === [] ? 0 : AccountBalance::query()->insertOrIgnore($preexisting));

                $chunk->delete();
                $import->recordStats(['balances' => $totals]);
            });
        }
    }

    /**
     * Split a chunk into the rows for accounts the import created and for the
     * user's own, keeping the last balance of each account and day.
     *
     * @param  list<BalanceRow>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function rowsFor(Import $import, ImportAccountMap $accounts, array $rows): array
    {
        $now = now();
        $split = [[], []];

        foreach ($rows as $row) {
            $account = $accounts->balanceTargetFor($row['account_key']);

            if ($account === null) {
                continue;
            }

            $split[$accounts->isPreexisting($account) ? 1 : 0][$account->id.'|'.$row['date']] = [
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'balance_date' => $row['date'],
                'balance' => $row['balance'],
                'derived' => false,
                'import_id' => $import->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [array_values($split[0]), array_values($split[1])];
    }
}
