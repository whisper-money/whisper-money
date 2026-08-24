<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignUuid('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role')->default('member');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_invitations');
    }
};
