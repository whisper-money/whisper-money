<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A low-balance problem the forecast found in an account's month. It is
     * kept so the email about it goes out once, and closed when it goes away.
     * One row per account and month: a soft alert turning strong, or one that
     * comes back after being resolved, updates it instead of adding another.
     */
    public function up(): void
    {
        Schema::create('forecast_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('space_id')->nullable()->index();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('level');
            $table->timestamp('first_seen_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_alerts');
    }
};
