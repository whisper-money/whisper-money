<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Left null for everybody, existing accounts included: the middleware fills
     * it in from the browser's own header on the next request, which is what
     * gets the fix to a user who never opens their settings. Backfilling it
     * here could only guess from `locale`, which is the guess the column exists
     * to replace.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('format_locale', 12)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('format_locale');
        });
    }
};
