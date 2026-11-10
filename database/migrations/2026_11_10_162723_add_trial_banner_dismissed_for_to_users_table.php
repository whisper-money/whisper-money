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
        Schema::table('users', function (Blueprint $table) {
            // "{trial end date}:{days threshold}" of the last trial-ending
            // banner the user closed; see TrialEndingBanner.
            $table->string('trial_banner_dismissed_for', 20)->nullable()->after('second_offer_shown_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trial_banner_dismissed_for');
        });
    }
};
