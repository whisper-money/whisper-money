<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The constraint `create_dashboard_widgets_table` left for when user
     * widgets existed: deleting a widget takes it off every dashboard.
     */
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->foreign('widget_definition_id')->references('id')->on('widget_definitions')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropForeign(['widget_definition_id']);
        });
    }
};
