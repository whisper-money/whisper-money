<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a user on the paywall chose to stay on the free plan, one row per answer.
 *
 * Several rows per user are allowed on purpose: someone who lands on the
 * paywall again later may have a different reason by then, and that change is
 * the interesting part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_plan_survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // `App\Enums\FreePlanSurveyReason`.
            $table->string('reason', 64);
            // Only ever set alongside the "other" reason.
            $table->text('details')->nullable();
            // Which paywall screen asked: soft, former-subscriber or hard.
            $table->string('gate', 32);
            $table->timestamps();

            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_plan_survey_responses');
    }
};
