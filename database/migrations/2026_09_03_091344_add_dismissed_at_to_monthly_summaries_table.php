<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dashboard notice used to remember its dismissal per browser, so it came
     * back on every other device and after every login. The summary owns that
     * state now: one summary, one reader, one dismissal.
     */
    public function up(): void
    {
        Schema::table('monthly_summaries', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_summaries', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
