<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A widget the user's agent wrote: its manifest, its TSX and the module it
     * compiled to, as of its current revision (`revision`). The runtime error
     * the dashboard last reported for it stays until a new revision replaces
     * the code that raised it.
     */
    public function up(): void
    {
        Schema::create('widget_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('manifest');
            $table->text('source');
            $table->longText('compiled_js');
            $table->unsignedInteger('revision');
            $table->json('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('created_via', 3);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_definitions');
    }
};
