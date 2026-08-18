<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archiving is its own thing, separate from hiding an account on the
     * dashboard, and it keeps the day it happened: the account stops counting
     * from then on while everything before that date reads as it always did.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('hidden_on_dashboard');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
