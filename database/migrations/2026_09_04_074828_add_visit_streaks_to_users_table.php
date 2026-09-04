<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days, and weeks, in a row the reader opened the app, kept on the row itself.
 *
 * There is no log of visits to count from — `last_active_at` is a single
 * timestamp, overwritten on every visit — so both runs are carried forward one
 * step at a time by the middleware that already writes that column. The longest
 * of each is kept alongside it because the nightly sweep is what turns a run
 * into a medal: a streak that peaked and broke between two sweeps would
 * otherwise go unrecorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('visit_streak')->default(0)->after('last_active_at');
            $table->unsignedSmallInteger('longest_visit_streak')->default(0)->after('visit_streak');
            $table->unsignedSmallInteger('visit_week_streak')->default(0)->after('longest_visit_streak');
            $table->unsignedSmallInteger('longest_visit_week_streak')->default(0)->after('visit_week_streak');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['visit_streak', 'longest_visit_streak', 'visit_week_streak', 'longest_visit_week_streak']);
        });
    }
};
