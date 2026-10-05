<?php

namespace App\Services\Imports;

use App\Enums\CategorySource;
use App\Enums\ImportChunkKind;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\Transaction;
use App\Services\TransactionDuplicateMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Writes the staged movements of a full import, chunk by chunk, leaving out
 * what the target account already holds.
 *
 * Each row goes through Eloquent rather than a bulk insert on purpose: the
 * automation rules and the budgets hang off `TransactionCreated`, and an
 * imported movement has to reach both the way a hand-made one does. A row
 * whose category came from the file is marked as categorized by hand, so a
 * rule never overrides what the user had in their previous app.
 *
 * @phpstan-type TransactionRow array{account_key: string, date: string, amount: int, description: string, notes?: ?string, category_key?: ?string, external_id?: ?string, currency_code?: ?string}
 */
class ImportTransactionWriter
{
    /** @var array<string, array<string, true>> account id => external ids it already holds */
    private array $externalIds = [];

    /** @var array<string, array<string, true>> account id => date|amount|description keys it held before the import */
    private array $duplicateKeys = [];

    /** @var array<string, array{account_id: string, name: string, created: bool, imported: int, duplicates: int}> */
    private array $perAccount = [];

    public function __construct(private TransactionDuplicateMatcher $duplicates) {}

    /**
     * @param  array<string, string>  $categoryIds  plan key => category id
     */
    public function write(Import $import, ImportAccountMap $accounts, array $categoryIds): void
    {
        $this->externalIds = $this->duplicateKeys = $this->perAccount = [];
        $totals = [
            'total' => $import->stagedRowCount(ImportChunkKind::Transactions),
            'processed' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'skipped' => 0,
        ];

        $import->recordStats(['transactions' => $totals]);

        $import->eachChunk(ImportChunkKind::Transactions, function (ImportChunk $chunk) use ($import, $accounts, $categoryIds, &$totals): void {
            DB::transaction(function () use ($chunk, $import, $accounts, $categoryIds, &$totals): void {
                foreach ($chunk->rows as $row) {
                    $totals[$this->writeRow($import, $accounts, $categoryIds, $row)]++;
                    $totals['processed']++;
                }
            });

            $import->recordStats(['transactions' => $totals, 'per_account' => array_values($this->perAccount)]);
        });
    }

    /**
     * @param  array<string, string>  $categoryIds
     * @param  TransactionRow  $row
     * @return 'imported'|'duplicates'|'skipped'
     */
    private function writeRow(Import $import, ImportAccountMap $accounts, array $categoryIds, array $row): string
    {
        $account = $accounts->targetFor($row['account_key']);

        if ($account === null) {
            return 'skipped';
        }

        if ($this->isDuplicate($accounts, $account, $row)) {
            $this->tally($accounts, $account, 'duplicates');

            return 'duplicates';
        }

        $categoryId = isset($row['category_key']) ? ($categoryIds[$row['category_key']] ?? null) : null;

        Transaction::query()->create([
            'user_id' => $import->user_id,
            // Spelled out so each row does not look its account's space up again.
            'space_id' => $account->space_id,
            'account_id' => $account->id,
            'import_id' => $import->id,
            'transaction_date' => $row['date'],
            'amount' => $row['amount'],
            'currency_code' => $row['currency_code'] ?? $account->currency_code,
            'description' => $row['description'],
            'notes' => $row['notes'] ?? null,
            'category_id' => $categoryId,
            'category_source' => $categoryId !== null ? CategorySource::Manual : null,
            'source' => TransactionSource::Imported,
            'external_transaction_id' => $row['external_id'] ?? null,
        ]);

        $this->tally($accounts, $account, 'imported');

        return 'imported';
    }

    /**
     * The file's own id makes a second import of the same file a no-op on any
     * account. An account that already held data is also checked the way the
     * per-account import checks it (same day, amount and description), against
     * what it held before this import: two identical coffees on one day in the
     * file are two coffees, not a duplicate.
     *
     * @param  TransactionRow  $row
     */
    private function isDuplicate(ImportAccountMap $accounts, Account $account, array $row): bool
    {
        $preexisting = $accounts->isPreexisting($account);
        $externalId = $row['external_id'] ?? null;

        if ($externalId !== null) {
            $known = $this->externalIdsOf($account, $preexisting);

            if (isset($known[$externalId])) {
                return true;
            }

            $this->externalIds[$account->id][$externalId] = true;
        }

        if (! $preexisting) {
            return false;
        }

        $this->duplicateKeys[$account->id] ??= $this->duplicates->existingKeys($account);

        return isset($this->duplicateKeys[$account->id][$this->duplicates->key($row['date'], (int) $row['amount'], $row['description'])]);
    }

    /**
     * @return array<string, true>
     */
    private function externalIdsOf(Account $account, bool $preexisting): array
    {
        return $this->externalIds[$account->id] ??= $preexisting
            ? $this->duplicates->existingExternalIds($account)
            : [];
    }

    /**
     * @param  'imported'|'duplicates'  $outcome
     */
    private function tally(ImportAccountMap $accounts, Account $account, string $outcome): void
    {
        $this->perAccount[$account->id] ??= [
            'account_id' => $account->id,
            'name' => $account->name,
            'created' => ! $accounts->isPreexisting($account),
            'imported' => 0,
            'duplicates' => 0,
        ];

        $this->perAccount[$account->id][$outcome]++;
    }
}
