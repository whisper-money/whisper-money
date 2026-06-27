<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill accounts and their owners left on the ISO 4217 "no currency"
     * placeholder "XXX". That code has no exchange rate, so every conversion
     * for such an account fell back to an unconverted (wrong) amount.
     *
     * Owners first: a user whose first account was XXX inherited XXX too, so
     * resolve those to the app default before accounts fall back to the owner.
     */
    public function up(): void
    {
        $default = strtoupper(config('cashier.currency', 'eur'));

        DB::table('users')
            ->where('currency_code', 'XXX')
            ->update(['currency_code' => $default]);

        DB::table('accounts')
            ->where('currency_code', 'XXX')
            ->orderBy('id')
            ->chunkById(200, function ($accounts): void {
                $userCurrencies = DB::table('users')
                    ->whereIn('id', collect($accounts)->pluck('user_id'))
                    ->pluck('currency_code', 'id');

                foreach ($accounts as $account) {
                    DB::table('accounts')
                        ->where('id', $account->id)
                        ->update(['currency_code' => strtoupper((string) ($userCurrencies[$account->user_id] ?? 'EUR'))]);
                }
            });
    }

    public function down(): void
    {
        // ponytail: irreversible — the original "XXX" carried no real currency.
    }
};
