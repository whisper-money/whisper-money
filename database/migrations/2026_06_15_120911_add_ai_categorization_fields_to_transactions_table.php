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
            $table->string('category_source')->nullable()->after('category_id');
            $table->decimal('ai_confidence', 4, 3)->nullable()->after('category_source');
            $table->foreignUuid('categorized_by_rule_id')
                ->nullable()
                ->after('ai_confidence')
                ->constrained('automation_rules')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categorized_by_rule_id');
            $table->dropColumn(['category_source', 'ai_confidence']);
        });
    }
};
