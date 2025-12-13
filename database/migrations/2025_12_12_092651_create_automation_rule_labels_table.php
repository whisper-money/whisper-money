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
        Schema::create('automation_rule_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('label_id')->constrained()->cascadeOnDelete();

            $table->unique(['automation_rule_id', 'label_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_rule_labels');
    }
};
