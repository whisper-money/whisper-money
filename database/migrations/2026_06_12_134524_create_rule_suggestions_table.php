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
        Schema::create('rule_suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('suggestion_run_id')->constrained()->cascadeOnDelete();
            $table->string('group_key');
            $table->string('match_field');
            $table->string('match_operator')->default('contains');
            $table->string('match_token');
            $table->foreignUuid('proposed_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('new_category_name')->nullable();
            $table->foreignUuid('new_category_parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('new_category_direction')->nullable();
            $table->decimal('confidence', 4, 3)->default(0);
            $table->unsignedInteger('group_size')->default(0);
            $table->json('sample_descriptions')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['suggestion_run_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_suggestions');
    }
};
