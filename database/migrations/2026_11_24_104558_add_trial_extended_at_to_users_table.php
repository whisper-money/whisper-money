<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the user's trial was extended (`App\Actions\Subscription\ExtendTrial`).
 * It is given once per user, ever, so cancelling again is never a way to get
 * another two weeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_extended_at')->nullable()->after('retention_coupon_redeemed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trial_extended_at');
        });
    }
};
