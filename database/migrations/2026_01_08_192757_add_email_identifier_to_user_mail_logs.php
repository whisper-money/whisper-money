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
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->string('email_identifier')->nullable()->after('email_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->dropColumn('email_identifier');
        });
    }
};
