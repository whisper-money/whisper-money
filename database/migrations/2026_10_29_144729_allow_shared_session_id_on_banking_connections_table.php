<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some banks keep a single valid session per user, so every connection the
     * user holds to that bank has to point at the same one. Nothing looks a
     * connection up by `session_id`, so the index goes with the constraint.
     */
    public function up(): void
    {
        Schema::table('banking_connections', function (Blueprint $table) {
            $table->dropUnique(['session_id']);
        });
    }

    /**
     * Reverse the migrations. Fails while two connections share a session.
     */
    public function down(): void
    {
        Schema::table('banking_connections', function (Blueprint $table) {
            $table->unique('session_id');
        });
    }
};
