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
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('type');
        });

        // Seed positions per user following the previous default ordering
        // (by type, then name) so the existing visual order is preserved.
        DB::table('accounts')
            ->orderByRaw("FIELD(type, 'checking', 'savings', 'investment', 'retirement', 'real_estate', 'loan', 'credit_card', 'others')")
            ->orderBy('name')
            ->get(['id', 'user_id'])
            ->groupBy('user_id')
            ->each(function ($accounts): void {
                $accounts->values()->each(function ($account, int $position): void {
                    DB::table('accounts')->where('id', $account->id)->update(['position' => $position]);
                });
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
