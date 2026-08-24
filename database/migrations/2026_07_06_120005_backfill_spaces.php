<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Stamp every existing user and their rows with a personal space before the
     * space-scoped reads go live in the same release, so no data disappears in
     * the window between adding the columns and switching the queries. Reuses the
     * idempotent, chunked `spaces:backfill` command (a no-op on a fresh install).
     */
    public function up(): void
    {
        Artisan::call('spaces:backfill');
    }

    public function down(): void
    {
        // ponytail: irreversible — spaces stay; dropping the columns (previous
        // migration) is what unwinds this.
    }
};
