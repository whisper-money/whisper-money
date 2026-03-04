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
            if (! Schema::hasColumn('user_leads', 'position')) {
                $table->unsignedInteger('position')->after('email');
            }

            if (! Schema::hasColumn('user_leads', 'referral_code')) {
                $table->string('referral_code', 12)->unique()->after('position');
            }

            if (! Schema::hasColumn('user_leads', 'referred_by_id')) {
                $table->char('referred_by_id', 36)->nullable()->after('referral_code');
                $table->foreign('referred_by_id')->references('id')->on('user_leads')->nullOnDelete();
            }
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
