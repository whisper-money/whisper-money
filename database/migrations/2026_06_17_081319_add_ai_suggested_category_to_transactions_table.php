<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('ai_suggested_category_id')
                ->nullable()
                ->after('categorized_by_rule_id')
                ->constrained('categories')
                ->nullOnDelete();
            $table->timestamp('ai_suggested_category_at')
                ->nullable()
                ->after('ai_suggested_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_suggested_category_id');
            $table->dropColumn('ai_suggested_category_at');
        });
    }
};
