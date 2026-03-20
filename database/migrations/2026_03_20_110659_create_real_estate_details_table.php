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
        Schema::create('real_estate_details', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('account_id')->unique()->constrained()->onDelete('cascade');
            $table->foreignUuid('linked_loan_account_id')->nullable()->constrained('accounts')->onDelete('set null');
            $table->string('property_type');
            $table->text('address')->nullable();
            $table->bigInteger('purchase_price')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('area_value', 12, 2)->nullable();
            $table->string('area_unit', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('real_estate_details');
    }
};
