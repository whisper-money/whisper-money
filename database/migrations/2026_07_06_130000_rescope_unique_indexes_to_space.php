<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Category, label and saved-filter names were unique per user. Now that a
     * user can own several spaces (each seeded with the same default category
     * names), those constraints must be per space instead — otherwise creating a
     * second space collides with the first space's categories. Runs after the
     * backfill, so space_id is already populated on every existing row.
     *
     * The old composite uniques also served as the index MySQL requires for the
     * `user_id` foreign key, so we add a plain `user_id` index before dropping
     * them (and drop it again on rollback once the composite is restored).
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->index('user_id', 'categories_user_id_index');
            $table->dropUnique('categories_user_id_parent_name_active_unique');
            $table->unique(
                ['space_id', 'parent_unique_marker', 'name', 'active_unique_marker'],
                'categories_space_id_parent_name_active_unique',
            );
        });

        Schema::table('labels', function (Blueprint $table) {
            $table->index('user_id', 'labels_user_id_index');
            $table->dropUnique('labels_user_id_name_deleted_at_unique');
            $table->unique(['space_id', 'name', 'deleted_at'], 'labels_space_id_name_deleted_at_unique');
        });

        Schema::table('saved_filters', function (Blueprint $table) {
            $table->index('user_id', 'saved_filters_user_id_index');
            $table->dropUnique('saved_filters_user_id_name_unique');
            $table->unique(['space_id', 'name'], 'saved_filters_space_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_space_id_parent_name_active_unique');
            $table->unique(
                ['user_id', 'parent_unique_marker', 'name', 'active_unique_marker'],
                'categories_user_id_parent_name_active_unique',
            );
            $table->dropIndex('categories_user_id_index');
        });

        Schema::table('labels', function (Blueprint $table) {
            $table->dropUnique('labels_space_id_name_deleted_at_unique');
            $table->unique(['user_id', 'name', 'deleted_at'], 'labels_user_id_name_deleted_at_unique');
            $table->dropIndex('labels_user_id_index');
        });

        Schema::table('saved_filters', function (Blueprint $table) {
            $table->dropUnique('saved_filters_space_id_name_unique');
            $table->unique(['user_id', 'name'], 'saved_filters_user_id_name_unique');
            $table->dropIndex('saved_filters_user_id_index');
        });
    }
};
