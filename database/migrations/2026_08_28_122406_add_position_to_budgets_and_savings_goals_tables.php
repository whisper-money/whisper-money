<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['budgets', 'savings_goals'];

    /**
     * Nullable with no backfill: a user who never drags anything keeps the
     * automatic attention-then-name ordering, and anything created later also
     * starts NULL so it lands at the end of a manually ordered list.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unsignedInteger('position')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('position');
            });
        }
    }
};
