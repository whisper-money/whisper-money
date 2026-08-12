<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The price-experiment arm the user was assigned as an anonymous visitor,
     * copied off their cookie at registration. Null means they are not in the
     * experiment (registered before it started, or arrived without a cookie)
     * and pay the control price.
     */
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
