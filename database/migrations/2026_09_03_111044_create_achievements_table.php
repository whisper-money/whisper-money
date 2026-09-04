<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per medal a user has earned.
 *
 * A medal records that something happened, so nothing here is ever revoked or
 * recomputed: `achieved_on` is the first day of the month it really happened,
 * reconstructed from the history on the first sweep, and the figure reached is
 * frozen beside it in the currency it was measured in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Labelled with the space the figures were read from, like
            // `monthly_summaries`, so a future shared space earns its own.
            $table->foreignUuid('space_id')->nullable()->constrained()->nullOnDelete();
            // `track.position` from the catalog, e.g. `net_worth.4`. Stable
            // across renames and threshold changes.
            $table->string('key', 64);
            // Month granularity: the history can say which month a milestone was
            // crossed in, never which day.
            $table->date('achieved_on');
            // The figure reached. `value` holds minor units for money, or a
            // plain count of transactions or months; `percent` holds a rate.
            // Only one is ever set, and which one the catalog decides.
            $table->bigInteger('value')->nullable();
            $table->decimal('percent', 8, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'achieved_on']);
            // The share of members holding a medal is one grouped count.
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
