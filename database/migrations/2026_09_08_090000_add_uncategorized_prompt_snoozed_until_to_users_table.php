<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Until when the "you have transactions with no category" prompt stays quiet.
 *
 * Kept on the user rather than in the browser, like every other prompt this app
 * puts away: somebody who says "not now" on their laptop has said it for their
 * phone too. Null means it has never been put away, or the snooze has run out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('uncategorized_prompt_snoozed_until')->nullable()->after('ai_consent_prompt_dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('uncategorized_prompt_snoozed_until');
        });
    }
};
