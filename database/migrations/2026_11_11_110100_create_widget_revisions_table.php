<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every saved version of a user widget, numbered from 1, so it can go back
     * to an earlier one. Going back saves a new revision with the old content
     * (`restored_from`), so the history only ever grows.
     */
    public function up(): void
    {
        Schema::create('widget_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('widget_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('manifest');
            $table->text('source');
            $table->longText('compiled_js');
            $table->string('created_via', 3);
            $table->unsignedInteger('restored_from')->nullable();
            $table->timestamps();

            $table->unique(['widget_definition_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_revisions');
    }
};
