<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Loans and real estate shipped on by default in the net worth chart. For a new
 * user that draws a first chart dominated by a mortgage or by a flat they
 * cannot spend, so both now start off and are opted into from the chart's own
 * settings.
 *
 * A `user_settings` row is only written the first time someone touches a
 * preference, so "no row" is what an untouched account looks like — including
 * every account that predates this change. Flipping the defaults on their own
 * would retroactively rewrite those users' net worth wherever it is read back:
 * the dashboard, the achievements history and the monthly summary email. The
 * backfill writes down the value they have been living with, so only genuinely
 * new users get the new default.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillUntouchedUsers();

        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('include_loans_in_net_worth_chart')->default(false)->change();
            $table->boolean('include_real_estate_in_net_worth_chart')->default(false)->change();
        });
    }

    /**
     * Writes down the value every user who never touched the toggles has been
     * living with. Public so the test can exercise it without the DDL above,
     * which MySQL commits implicitly and would leak rows past the rollback.
     */
    public function backfillUntouchedUsers(): void
    {
        DB::table('users')
            ->select('id')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('user_settings')
                ->whereColumn('user_settings.user_id', 'users.id'))
            ->chunkById(500, function (Collection $users): void {
                DB::table('user_settings')->insert($users->map(fn ($user): array => [
                    'id' => (string) Str::orderedUuid(),
                    'user_id' => $user->id,
                    'include_loans_in_net_worth_chart' => true,
                    'include_real_estate_in_net_worth_chart' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });
    }

    /**
     * Restores the column defaults only. The backfilled rows stay: they hold the
     * value those users already had, and nothing tells them apart from a row
     * somebody wrote by hand.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('include_loans_in_net_worth_chart')->default(true)->change();
            $table->boolean('include_real_estate_in_net_worth_chart')->default(true)->change();
        });
    }
};
