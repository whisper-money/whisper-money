<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A goal's progress is derived from the transactions carrying its label, and
     * archiving deletes that label — so the amount saved has to be snapshotted
     * here, or an archived goal would read back as nothing but its starting
     * balance and would drift with every later change to those transactions.
     */
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('target_date');
            $table->integer('archived_saved_amount')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'archived_saved_amount']);
        });
    }
};
