<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_request_votes', function (Blueprint $table) {
            // The integration_request_id foreign key relies on the composite
            // unique index; give it a standalone index before dropping the
            // unique so users may back the same integration multiple times.
            $table->index('integration_request_id');
            $table->dropUnique(['integration_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_request_votes', function (Blueprint $table) {
            $table->unique(['integration_request_id', 'user_id']);
            $table->dropIndex(['integration_request_id']);
        });
    }
};
