<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('budgets')
            ->whereNotNull('category_id')
            ->orderBy('id')
            ->each(function ($budget) {
                DB::table('budget_category')->insertOrIgnore([
                    'budget_id' => $budget->id,
                    'category_id' => $budget->category_id,
                ]);
            });

        DB::table('budgets')
            ->whereNotNull('label_id')
            ->orderBy('id')
            ->each(function ($budget) {
                DB::table('budget_label')->insertOrIgnore([
                    'budget_id' => $budget->id,
                    'label_id' => $budget->label_id,
                ]);
            });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('label_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('label_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::table('budget_category')->orderBy('budget_id')->each(function ($row) {
            DB::table('budgets')
                ->where('id', $row->budget_id)
                ->whereNull('category_id')
                ->update(['category_id' => $row->category_id]);
        });

        DB::table('budget_label')->orderBy('budget_id')->each(function ($row) {
            DB::table('budgets')
                ->where('id', $row->budget_id)
                ->whereNull('label_id')
                ->update(['label_id' => $row->label_id]);
        });
    }
};
