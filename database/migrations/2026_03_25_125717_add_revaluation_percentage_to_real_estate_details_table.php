<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('real_estate_details', function (Blueprint $table) {
            $table->decimal('revaluation_percentage', 5, 2)->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('real_estate_details', function (Blueprint $table) {
            $table->dropColumn('revaluation_percentage');
        });
    }
};
