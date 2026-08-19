<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The MCP settings surface is now open to everyone, so App\Features\Mcp no longer
 * exists. Pennant resolved it on every authenticated Inertia render, so it left a
 * value row per user that nothing can ever read again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->where('name', 'App\Features\Mcp')
            ->delete();
    }

    public function down(): void
    {
        // The assignments are gone for good; the feature they belonged to no longer exists.
    }
};
