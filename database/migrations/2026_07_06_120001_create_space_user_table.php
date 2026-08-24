<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();

            $table->unique(['space_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_user');
    }
};
