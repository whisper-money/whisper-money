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
        Schema::table('budgets', function (Blueprint $table) {
            $table->boolean('notify_on_new_transaction')->default(false);
            $table->boolean('notify_on_close_to_limit')->default(false);
            $table->boolean('notify_on_over_limit')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn([
                'notify_on_new_transaction',
                'notify_on_close_to_limit',
                'notify_on_over_limit',
            ]);
        });
    }
};
