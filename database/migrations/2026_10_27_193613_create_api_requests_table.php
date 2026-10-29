<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One append-only row per authenticated `/api/v1` request, so we can tell
     * which endpoints get used, by whom, through which credential (a personal
     * token or an OAuth client) and how they answered. Never financial data.
     *
     * Raw rows like `mcp_tool_calls`: roll them up or prune them if the table
     * ever grows past a few million rows.
     */
    public function up(): void
    {
        Schema::create('api_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('endpoint');
            $table->string('credential', 10);
            $table->string('oauth_client_id')->nullable();
            $table->unsignedSmallInteger('status');
            $table->timestamp('created_at');

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
    }
};
