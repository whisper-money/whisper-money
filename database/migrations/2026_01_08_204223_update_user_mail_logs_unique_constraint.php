<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, populate email_identifier for existing records
        // For drip emails, use the email_type as the identifier
        DB::table('user_mail_logs')
            ->whereNull('email_identifier')
            ->update([
                'email_identifier' => DB::raw('email_type'),
            ]);

        Schema::table('user_mail_logs', function (Blueprint $table) {
            // Make email_identifier non-nullable
            $table->string('email_identifier')->nullable(false)->change();
        });

        // Drop the foreign key constraint temporarily
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->dropForeign('user_mail_logs_user_id_foreign');
        });

        // Now drop the old unique constraint
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->dropUnique('user_mail_logs_user_id_email_type_unique');
        });

        // Add new unique constraint that includes email_identifier
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->unique(['user_id', 'email_type', 'email_identifier'], 'user_mail_logs_unique');
        });

        // Re-add the foreign key constraint
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the foreign key constraint
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        // Drop the new constraint
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->dropUnique('user_mail_logs_unique');
        });

        // Re-add the old constraint
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->unique(['user_id', 'email_type'], 'user_mail_logs_user_id_email_type_unique');
        });

        // Re-add the foreign key
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Make email_identifier nullable again
        Schema::table('user_mail_logs', function (Blueprint $table) {
            $table->string('email_identifier')->nullable()->change();
        });
    }
};
