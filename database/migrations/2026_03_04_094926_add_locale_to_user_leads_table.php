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
        Schema::table('user_leads', function (Blueprint $table) {
            $table->string('locale', 5)->default('en')->after('referred_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_leads', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
