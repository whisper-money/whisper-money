<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_requests', function (Blueprint $table) {
            $table->string('status')->default('pending')->index()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('integration_requests', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
