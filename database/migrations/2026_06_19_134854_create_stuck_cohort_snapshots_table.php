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
        Schema::create('stuck_cohort_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('onboarded_count');
            $table->unsignedInteger('stuck_count');
            $table->decimal('stuck_pct', 5, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stuck_cohort_snapshots');
    }
};
