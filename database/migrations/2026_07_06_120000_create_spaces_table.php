<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('personal')->default(false);
            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spaces');
    }
};
