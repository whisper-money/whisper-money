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
        Schema::create('account_import_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('config');
            $table->timestamps();

            $table->unique(['account_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_import_configs');
    }
};
