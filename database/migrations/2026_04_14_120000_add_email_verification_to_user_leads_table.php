<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_leads', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->unsignedInteger('position')->nullable()->change();
            $table->string('referral_code', 12)->nullable()->change();
        });

        DB::table('user_leads')
            ->whereNull('email_verified_at')
            ->update([
                'email_verified_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_leads', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable(false)->change();
            $table->unsignedInteger('position')->nullable(false)->change();
            $table->dropColumn('email_verified_at');
        });
    }
};
