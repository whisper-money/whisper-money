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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('ai_model')->nullable()->after('ai_suggested_category_at');
        });

        // Existing AI suggestions predate this column. They were produced by the
        // floating `gemini-flash-latest` alias, which by then resolved to
        // gemini-3.5-flash, so stamp that as the model used.
        // ponytail: raw update so the backfill doesn't bump every row's updated_at.
        DB::table('transactions')
            ->whereNotNull('ai_suggested_category_at')
            ->whereNull('ai_model')
            ->update(['ai_model' => 'gemini-3.5-flash']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('ai_model');
        });
    }
};
