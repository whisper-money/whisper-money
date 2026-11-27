<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The side an adjustment moves: "income" or "expense". It used to follow
     * from the category, which left an adjustment without one always on
     * "Other spending"; existing rows keep that reading (income when their
     * category is an income one).
     */
    public function up(): void
    {
        Schema::table('forecast_estimate_adjustments', function (Blueprint $table) {
            $table->string('kind', 16)->default('expense')->after('category_id');
        });

        DB::table('forecast_estimate_adjustments')
            ->whereIn('category_id', DB::table('categories')->where('type', 'income')->select('id'))
            ->update(['kind' => 'income']);
    }

    public function down(): void
    {
        Schema::table('forecast_estimate_adjustments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
