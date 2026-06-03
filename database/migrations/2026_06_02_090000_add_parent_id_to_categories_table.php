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
            $table->uuid('parent_id')->nullable()->after('user_id');
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
            $table->index('parent_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('parent_unique_marker')
                ->nullable()
                ->virtualAs("coalesce(`parent_id`, 'root')");
        });

        // Create the new uniqueness index before dropping the old one so the
        // user_id foreign key always has a supporting (user_id-leading) index.
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'parent_unique_marker', 'name', 'active_unique_marker'],
                'categories_user_id_parent_name_active_unique'
            );
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_user_id_name_active_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['user_id', 'name', 'active_unique_marker'], 'categories_user_id_name_active_unique');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_user_id_parent_name_active_unique');
            $table->dropColumn('parent_unique_marker');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
