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
        Schema::table('banking_connections', function (Blueprint $table) {
            $table->string('state_token')->nullable()->unique()->after('authorization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banking_connections', function (Blueprint $table) {
            $table->dropUnique(['state_token']);
            $table->dropColumn('state_token');
        });
    }
};
