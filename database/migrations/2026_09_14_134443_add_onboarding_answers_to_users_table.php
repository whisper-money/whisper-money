<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the onboarding asked and the user answered: their goal, how they
     * track their money today, and what they guess they spent last month.
     *
     * One JSON column rather than three, because only the guess is read by the
     * flow today. The rest is kept because it costs nothing to keep and the
     * question was already asked.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('onboarding_answers')->nullable()->after('onboarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_answers');
        });
    }
};
