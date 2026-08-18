<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hiding an account from the dashboard now removes it from every listing
     * and picker, which is archiving; the column takes the honest name.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('hidden_on_dashboard', 'archived');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('archived', 'hidden_on_dashboard');
        });
    }
};
