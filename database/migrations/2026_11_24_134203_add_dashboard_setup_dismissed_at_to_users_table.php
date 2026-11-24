<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the user hid the "Finish setting up" card on the dashboard.
 *
 * Kept on the user rather than in the browser, like the other prompts the app
 * puts away: hiding it on the laptop hides it on the phone too. Null means it
 * was never hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('dashboard_setup_dismissed_at')->nullable()->after('uncategorized_prompt_snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_setup_dismissed_at');
        });
    }
};
