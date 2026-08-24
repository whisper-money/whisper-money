<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Splitting a transaction keeps the original row and points every part back
     * at it. The original is soft-deleted so it drops out of every list and
     * total at once — the parts are what counts from then on — while its
     * `dedup_fingerprint` stays where the bank sync looks for it (that lookup
     * already reads trashed rows), so the next sync never re-creates it.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('split_parent_id')
                ->nullable()
                ->after('account_id')
                ->constrained('transactions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['split_parent_id']);
            $table->dropColumn('split_parent_id');
        });
    }
};
