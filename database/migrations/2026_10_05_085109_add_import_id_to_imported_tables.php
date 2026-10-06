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
     * any later edit of the row.
     *
     * The small tables get a foreign key that nulls the link when the history
     * row goes. `transactions` and `account_balances` get a plain indexed
     * column instead, like `space_id` before them: adding a foreign key there
     * makes MySQL copy the whole table. Undo deletes by `import_id` explicitly,
     * and an import that is deleted clears the link in code (`Import::booted`).
     *
     * Each table is checked first, so a run that stopped halfway can run again.
     *
     * @var list<string>
     */
    private array $constrained = ['accounts', 'categories', 'banks'];

    /** @var list<string> */
    private array $indexed = ['transactions', 'account_balances'];

    public function up(): void
    {
        foreach ($this->constrained as $tableName) {
            if (Schema::hasColumn($tableName, 'import_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignUuid('import_id')
                    ->nullable()
                    ->constrained('imports')
                    ->nullOnDelete();
            });
        }

        foreach ($this->indexed as $tableName) {
            if (Schema::hasColumn($tableName, 'import_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('import_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->constrained as $tableName) {
            if (! Schema::hasColumn($tableName, 'import_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['import_id']);
                $table->dropColumn('import_id');
            });
        }

        foreach ($this->indexed as $tableName) {
            if (! Schema::hasColumn($tableName, 'import_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['import_id']);
                $table->dropColumn('import_id');
            });
        }
    }
};
