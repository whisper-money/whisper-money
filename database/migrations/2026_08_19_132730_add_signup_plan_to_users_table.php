<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which landing pricing card the user registered from, so onboarding can
     * stop offering paid features to someone who chose the free plan. Null for
     * every other entry point, which keeps the original flow.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_plan')->nullable()->after('price_arm');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signup_plan');
        });
    }
};
