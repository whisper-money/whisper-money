<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user and (UTC) day they opened the app, so the admin chart can
 * count who was active in any 30-day window. `last_active_at` alone cannot:
 * it is overwritten on every visit.
 *
 * Seeded with each user's last active day, which makes today's 30-day count
 * exact from the start; earlier days fill in as the log grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_active_days', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->primary(['user_id', 'date']);
            $table->index(['date', 'user_id']);
        });

        DB::table('user_active_days')->insertUsing(
            ['user_id', 'date'],
            DB::table('users')
                ->whereNotNull('last_active_at')
                ->selectRaw('id, DATE(last_active_at)'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_active_days');
    }
};
