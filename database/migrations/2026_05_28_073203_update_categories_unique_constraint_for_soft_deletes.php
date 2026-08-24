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
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'name']);
            $table->boolean('active_unique_marker')
                ->nullable()
                ->virtualAs('if(`deleted_at` is null, 1, null)');
            $table->unique(['user_id', 'name', 'active_unique_marker'], 'categories_user_id_name_active_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique('categories_user_id_name_active_unique');
            $table->dropColumn('active_unique_marker');
            $table->unique(['user_id', 'name']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
