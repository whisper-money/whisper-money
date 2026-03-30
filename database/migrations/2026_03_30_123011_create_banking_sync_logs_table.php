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
        Schema::create('banking_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('banking_connection_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->text('error_message')->nullable();
            $table->string('error_class')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banking_sync_logs');
    }
};
