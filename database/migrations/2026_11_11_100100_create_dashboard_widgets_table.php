<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A widget is either one of the app's own (`system_widget`, a registry id)
     * or one the user wrote (`widget_definition_id`). The second has no foreign
     * key yet: `widget_definitions` does not exist until user widgets land, and
     * that migration adds the constraint.
     */
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('system_widget', 50)->nullable();
            $table->uuid('widget_definition_id')->nullable()->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('width', 3);
            $table->string('height', 1);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['dashboard_id', 'position']);
            // The registry draws each system widget once per dashboard. NULLs do
            // not collide, so user widgets are not limited by it.
            $table->unique(['dashboard_id', 'system_widget']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
