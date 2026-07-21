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
        Schema::create('personal_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 8, 2);
            $table->string('currency', 3)->default('EUR');
            $table->enum('billing_cycle', ['weekly', 'monthly', 'biweekly', 'yearly'])->default('monthly');
            $table->date('next_billing_date');
            $table->string('color')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_subscriptions');
    }
};
