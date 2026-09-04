<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many medals a reader holds, kept on the row itself.
 *
 * The account menu shows the count on every screen, and the authenticated user
 * is already loaded there — so the alternative was a `count(*)` on every render
 * of every page for a number that moves once a day. The nightly sweep writes
 * this, and re-writes it even when it awards nothing, so a row deleted by hand
 * is corrected by the next pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('achievements_count')->default(0)->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('achievements_count');
        });
    }
};
