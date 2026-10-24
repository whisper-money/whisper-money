<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX = 'idx_transactions_budget_lookup';

    private const string STAGING_INDEX = 'idx_transactions_budget_lookup_next';

    /**
     * Put `deleted_at` among the equality columns of the per-user index, so
     * range scans skip soft-deleted rows instead of reading them and filtering
     * on the full row. `id` comes right after the date so the index also serves
     * the transactions list's `transaction_date desc, id desc` order, and
     * `category_id` stays last for index-only reads and category pushdown.
     *
     * Production gets the index by hand before deploy (see the PR), so this is a
     * no-op there; elsewhere it builds online and swaps the name in place.
     */
    public function up(): void
    {
        $this->reshape(['user_id', 'deleted_at', 'transaction_date', 'id', 'category_id']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->reshape(['user_id', 'transaction_date', 'category_id']);
    }

    /**
     * Build the new shape under a staging name, then drop the old index and
     * rename the staging one in a single metadata-only statement, so the
     * `user_id` foreign key is never left without an index. Each step is skipped
     * when it has already run.
     *
     * @param  list<string>  $columns
     */
    private function reshape(array $columns): void
    {
        if ($this->columnsOf(self::INDEX) === $columns) {
            return;
        }

        $staged = $this->columnsOf(self::STAGING_INDEX);

        if ($staged !== $columns) {
            if ($staged !== null) {
                DB::statement('ALTER TABLE transactions DROP INDEX '.self::STAGING_INDEX);
            }

            DB::statement(sprintf(
                'ALTER TABLE transactions ADD INDEX %s (%s), ALGORITHM=INPLACE, LOCK=NONE',
                self::STAGING_INDEX,
                implode(', ', $columns),
            ));
        }

        $dropCurrent = $this->columnsOf(self::INDEX) !== null ? 'DROP INDEX '.self::INDEX.', ' : '';

        DB::statement(sprintf(
            'ALTER TABLE transactions %sRENAME INDEX %s TO %s, ALGORITHM=INPLACE, LOCK=NONE',
            $dropCurrent,
            self::STAGING_INDEX,
            self::INDEX,
        ));
    }

    /**
     * @return list<string>|null
     */
    private function columnsOf(string $index): ?array
    {
        $found = collect(Schema::getIndexes('transactions'))->firstWhere('name', $index);

        return $found['columns'] ?? null;
    }
};
