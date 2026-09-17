<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The generating screen counts merchants while the run is still going, so
     * the number has to live on the run rather than be recomputed per poll.
     */
    public function up(): void
    {
        Schema::table('suggestion_runs', function (Blueprint $table) {
            $table->unsignedInteger('merchants_considered')->default(0)->after('transactions_considered');
        });
    }

    public function down(): void
    {
        Schema::table('suggestion_runs', function (Blueprint $table) {
            $table->dropColumn('merchants_considered');
        });
    }
};
