<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the user who gave an answer went on to start a trial or a paid plan,
 * so the reasons for staying free can be read against who came back.
 * Stamped by `App\Listeners\MarkFreePlanSurveyResponsesConverted`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('free_plan_survey_responses', function (Blueprint $table) {
            $table->timestamp('converted_at')->nullable()->after('gate');
        });
    }

    public function down(): void
    {
        Schema::table('free_plan_survey_responses', function (Blueprint $table) {
            $table->dropColumn('converted_at');
        });
    }
};
