<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price-experiment arm the user was drawn into as an anonymous visitor,
 * copied off their cookie at registration. Null meant they were never in the
 * experiment — registered before it started, or arrived without a cookie — and
 * paid the control price.
 *
 * Retained after the experiment ended on the `high` arm: nothing reads the column
 * anymore, but it is the only record of which price each user was quoted and
 * charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('price_arm')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('price_arm');
        });
    }
};
