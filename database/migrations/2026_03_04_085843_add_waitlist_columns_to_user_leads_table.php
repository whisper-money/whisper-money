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
            $table->unsignedInteger('position')->after('email');
            $table->string('referral_code', 12)->unique()->after('position');
            $table->char('referred_by_id', 36)->nullable()->after('referral_code');

            $table->foreign('referred_by_id')->references('id')->on('user_leads')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_leads', function (Blueprint $table) {
            $table->dropForeign(['referred_by_id']);
            $table->dropColumn(['position', 'referral_code', 'referred_by_id']);
        });
    }
};
