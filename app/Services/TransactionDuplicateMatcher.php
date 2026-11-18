<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tells which incoming rows an account already holds, for the imports that
 * bring a file in on top of existing data: the per-account import drawer asks
 * through the check-duplicates endpoint, the full import asks from its job.
 *
 * A row matches on the same day, the exact amount (both in integer minor
 * units) and the description compared case-insensitively with collapsed
 * whitespace. A row that carries the id its own app gave it also matches when
 * the account already holds that id, whatever its day, amount or description.
 */
class TransactionDuplicateMatcher
{
    /**
     * Flag which of the given rows already exist on the account, one boolean
     * per row, in order.
     *
     * @param  list<array{transaction_date: string, amount: int|string, description: string, external_id?: string|null}>  $incoming
     * @return list<bool>
     */
    public function flag(Account $account, array $incoming): array
    {
        if ($incoming === []) {
            return [];
        }

        $dates = array_map(fn (array $row): string => substr($row['transaction_date'], 0, 10), $incoming);
        $seen = $this->existingKeys($account, min($dates), max($dates));
        $heldExternalIds = $this->heldExternalIds($account, $incoming);

        return array_map(
            fn (array $row): bool => isset($heldExternalIds[$row['external_id'] ?? '']) || isset($seen[$this->key(
                substr($row['transaction_date'], 0, 10),
                (int) $row['amount'],
                $row['description'],
            )]),
            $incoming,
        );
    }

    /**
     * Which of the external ids the incoming rows carry the account already
     * holds, as a set. Only those ids are looked up, so a file without them
     * costs no query.
     *
     * @param  list<array{external_id?: string|null}>  $incoming
     * @return array<string, true>
     */
    private function heldExternalIds(Account $account, array $incoming): array
    {
        $wanted = array_values(array_unique(array_filter(
            array_map(fn (array $row): ?string => $row['external_id'] ?? null, $incoming),
            fn (?string $id): bool => $id !== null && $id !== '',
        )));

        if ($wanted === []) {
            return [];
        }

        $ids = $this->heldTransactions($account, null)
            ->whereIn('external_transaction_id', $wanted)
            ->pluck('external_transaction_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * The keys of every row the account holds between two days, inclusive, as
     * a set. Without bounds, the whole history. An import in progress passes
     * its own id, so what it wrote itself is never taken for something the
     * account already held.
     *
     * @return array<string, true>
     */
    public function existingKeys(Account $account, ?string $from = null, ?string $to = null, ?string $exceptImportId = null): array
    {
        $existing = $this->heldTransactions($account, $exceptImportId)
            ->when($from !== null && $to !== null, fn (Builder $query) => $query->whereBetween('transaction_date', [$from, $to]))
            ->get(['transaction_date', 'amount', 'description']);

        $seen = [];

        foreach ($existing as $transaction) {
            $seen[$this->key(
                $transaction->transaction_date->format('Y-m-d'),
                (int) $transaction->amount,
                $transaction->description,
            )] = true;
        }

        return $seen;
    }

    /**
     * The ids another app gave the rows the account holds, as a set, leaving
     * out the import asking. What the full import reads to make a second run
     * of the same file a no-op.
     *
     * @return array<string, true>
     */
    public function existingExternalIds(Account $account, ?string $exceptImportId = null): array
    {
        $ids = $this->heldTransactions($account, $exceptImportId)
            ->whereNotNull('external_transaction_id')
            ->pluck('external_transaction_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * The rows that count as already on the account.
     *
     * A split parent is soft-deleted but its money is still on the account,
     * spread over its parts, so the row that produced it is already imported.
     * Rows the user deleted on purpose stay invisible here and can be
     * re-imported - as can a parent whose parts are all gone, since it has no
     * live parts left to hold the money.
     *
     * @return Builder<Transaction>
     */
    private function heldTransactions(Account $account, ?string $exceptImportId): Builder
    {
        return Transaction::withTrashed()
            ->where('account_id', $account->id)
            ->where(fn (Builder $query) => $query->whereNull('deleted_at')->orWhereHas('splits'))
            ->when($exceptImportId !== null, fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->whereNull('import_id')->orWhere('import_id', '!=', $exceptImportId),
            ));
    }

    public function key(string $date, int $amount, string $description): string
    {
        // Collapse every Unicode whitespace run (matching JS \s, which includes
        // the non-breaking spaces common in bank statements) to a single space,
        // then trim. PHP's default \s is ASCII-only, so without this an existing
        // "Coffee Shop" and an imported "Coffee Shop" would not be seen as
        // the same row, unlike the old client-side check.
        $normalized = trim((string) preg_replace(
            '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u',
            ' ',
            mb_strtolower($description),
        ));

        return $date.'|'.$amount.'|'.$normalized;
    }
}
