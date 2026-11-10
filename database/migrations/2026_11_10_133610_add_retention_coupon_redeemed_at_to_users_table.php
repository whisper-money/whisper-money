<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the user took the cancel flow's retention discount. It is offered once
 * per user, ever, so cancelling again is never a way to get another one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('retention_coupon_redeemed_at')->nullable()->after('second_offer_shown_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('retention_coupon_redeemed_at');
        });
    }
};
