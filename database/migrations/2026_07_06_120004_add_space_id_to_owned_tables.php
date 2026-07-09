<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables whose rows belong to a space (the tenant). We add a plain indexed
     * column rather than a foreign key: adding an FK to large tables such as
     * `transactions` triggers a validating table scan/lock on Postgres, which is
     * exactly what a phased, zero-downtime backfill needs to avoid. Referential
     * integrity for space deletion is enforced in application code instead.
     *
     * ponytail: indexed column, no FK. Add the FK + NOT NULL once prod has been
     * backfilled and space deletion reassigns rows (see spaces:backfill command).
     *
     * @var list<string>
     */
    private array $tables = [
        'accounts',
        'banking_connections',
        'transactions',
        'categories',
        'labels',
        'budgets',
        'automation_rules',
        'saved_filters',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('space_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('space_id');
            });
        }
    }
};
