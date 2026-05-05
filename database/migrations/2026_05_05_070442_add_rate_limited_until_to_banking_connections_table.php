<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banking_connections', function (Blueprint $table): void {
            $table->dateTime('rate_limited_until')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('banking_connections', function (Blueprint $table): void {
            $table->dropColumn('rate_limited_until');
        });
    }
};
