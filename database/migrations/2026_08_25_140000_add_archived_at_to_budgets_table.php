<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An archived budget stops counting from the day it was archived: no new
     * transactions land in it and no new periods are generated, while the
     * periods it already has keep the figures they had.
     */
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_catch_all');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
