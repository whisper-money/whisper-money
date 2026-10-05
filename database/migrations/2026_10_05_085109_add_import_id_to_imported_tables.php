<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every row a full import writes points back at it, so undoing the import
     * removes exactly what it wrote and nothing the user had before.
     *
     * A column per table rather than a pivot: an import writes thousands of
     * transactions, undo is one indexed delete per table, and the link survives
     * any later edit of the row. `nullOnDelete` because the import row is only
     * the history entry: losing it must never take the user's data with it.
     *
     * @var list<string>
     */
    private array $tables = ['accounts', 'categories', 'transactions', 'account_balances'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignUuid('import_id')
                    ->nullable()
                    ->constrained('imports')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['import_id']);
                $table->dropColumn('import_id');
            });
        }
    }
};
