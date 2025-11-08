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
        // Drop the existing id column and recreate it as UUID
        Schema::table('transactions', function (Blueprint $table) {
            // Drop the existing auto-increment id
            $table->dropColumn('id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Add new UUID id as primary key
            $table->uuid('id')->primary()->first();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->id()->first();
        });
    }
};
