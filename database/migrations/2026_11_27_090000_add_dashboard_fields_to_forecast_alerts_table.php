<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the dashboard's warning card says about an alert, kept with it so
     * the dashboard reads it instead of projecting the forecast: the lowest
     * balance of a strong month, the day it falls on and the charges behind
     * it. `snoozed_until` is the day the user asked to be reminded on; the
     * card stays hidden until then.
     */
    public function up(): void
    {
        Schema::table('forecast_alerts', function (Blueprint $table) {
            $table->bigInteger('lowest_balance')->nullable();
            $table->date('lowest_on')->nullable();
            $table->json('causes')->nullable();
            $table->date('snoozed_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forecast_alerts', function (Blueprint $table) {
            $table->dropColumn(['lowest_balance', 'lowest_on', 'causes', 'snoozed_until']);
        });
    }
};
