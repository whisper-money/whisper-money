<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_request_votes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['integration_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_request_votes');
    }
};
