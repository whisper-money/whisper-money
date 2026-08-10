<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One append-only row per MCP tool call, so we can answer which tools get
     * used, by whom and how often.
     *
     * ponytail: raw rows instead of pre-aggregated counters — the volume is a
     * handful of calls per Pro user per day, so GROUP BY answers every question
     * we might ask later. Add a prune command (or roll up into daily counters)
     * if the table ever grows past a few million rows.
     */
    public function up(): void
    {
        Schema::create('mcp_tool_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool');
            $table->timestamps();

            $table->index(['tool', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
    }
};
