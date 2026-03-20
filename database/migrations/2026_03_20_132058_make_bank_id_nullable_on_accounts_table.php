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
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign('accounts_bank_id_foreign');
            $table->char('bank_id', 36)->nullable()->change();
            $table->foreign('bank_id')->references('id')->on('banks')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['bank_id']);
            $table->char('bank_id', 36)->nullable(false)->change();
            $table->foreign('bank_id')->references('id')->on('banks')->cascadeOnDelete();
        });
    }
};
