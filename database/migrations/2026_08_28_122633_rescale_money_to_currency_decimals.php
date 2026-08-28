<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every money column stored minor units at a fixed scale of 2 decimals,
 * whatever the currency. This rescales the stored integers to each currency's
 * real scale: COP, CLP, PYG, JPY and PKR have no minor unit in practice, KWD
 * has three decimals, BTC has eight.
 *
 * The scales are hardcoded rather than read from `config/currencies.php` on
 * purpose. A migration is one fixed transition between two known states; if it
 * read the config it would rescale a currency added years later, on a database
 * that never held the old scale.
 *
 * Scaling down to 0 decimals is lossy by design — the centavos on ~2,000 COP
 * rows are dropped, which the product owner accepted. `down()` restores the
 * scale, never those centavos, so take a backup before running this.
 */
return new class extends Migration
{
    /**
     * Currency codes grouped by how far their scale moves from the old fixed 2.
     * A shift of -2 means "divide by 100", of 6 means "multiply by a million".
     */
    private const SHIFTS = [
        -2 => ['COP', 'CLP', 'PYG', 'JPY', 'PKR'],
        1 => ['KWD'],
        6 => ['BTC'],
    ];

    /**
     * Every money column in the database, with the predicate that reaches the
     * currency setting its scale. Only `transactions` carries a currency of its
     * own; the other six resolve it through a subquery, which is the reason a
     * per-currency scale costs more than a config key.
     *
     * `(?)` is expanded to one placeholder per currency code.
     *
     * @var list<array{0: string, 1: list<string>, 2: string}>
     */
    private const MONEY_COLUMNS = [
        ['transactions', ['amount'], 'currency_code in (?)'],
        ['account_balances', ['balance', 'invested_amount'], 'account_id in (select id from accounts where currency_code in (?))'],
        ['real_estate_details', ['purchase_price'], 'account_id in (select id from accounts where currency_code in (?))'],
        ['loan_details', ['original_amount'], 'account_id in (select id from accounts where currency_code in (?))'],
        // A budget transaction's amount is a snapshot of the transaction's own
        // amount, copied without conversion, so it follows the transaction's
        // currency and not the budget owner's primary one.
        ['budget_transactions', ['amount'], 'transaction_id in (select id from transactions where currency_code in (?))'],
        ['budget_periods', ['allocated_amount', 'carried_over_amount'], 'budget_id in (select id from budgets where user_id in (select id from users where currency_code in (?)))'],
        ['savings_goals', ['target_amount', 'initial_amount', 'archived_saved_amount'], 'user_id in (select id from users where currency_code in (?))'],
    ];

    public function up(): void
    {
        // The one money column still on `int`, which caps at 2.1e9 minor units.
        // A three- or eight-decimal currency overflows that on a real balance.
        Schema::table('savings_goals', function (Blueprint $table): void {
            $table->bigInteger('archived_saved_amount')->nullable()->change();
        });

        foreach (self::SHIFTS as $shift => $codes) {
            $this->rescale($codes, $shift);
        }
    }

    public function down(): void
    {
        foreach (self::SHIFTS as $shift => $codes) {
            $this->rescale($codes, -$shift);
        }

        // `archived_saved_amount` stays bigint: narrowing it back would fail on
        // any row an eight-decimal currency legitimately grew past 2.1e9.
    }

    /**
     * Move every money column of the given currencies by a power of ten.
     *
     * Timestamps are deliberately left alone. The offline client syncs off
     * `updated_at`, so re-dating 20k rows would make every client re-pull its
     * whole history; it drops the now-wrongly-scaled cache through a Dexie
     * version bump instead.
     *
     * @param  list<string>  $codes
     */
    private function rescale(array $codes, int $shift): void
    {
        $factor = 10 ** abs($shift);
        $placeholders = implode(',', array_fill(0, count($codes), '?'));

        foreach (self::MONEY_COLUMNS as [$table, $columns, $predicate]) {
            $assignments = implode(', ', array_map(
                fn (string $column): string => $shift > 0
                    // Scaling up is exact; scaling down rounds half away from
                    // zero, matching PHP's round() and losing sub-unit detail.
                    ? "{$column} = {$column} * {$factor}"
                    : "{$column} = round({$column} / {$factor})",
                $columns
            ));

            DB::update(
                "update {$table} set {$assignments} where ".str_replace('(?)', "({$placeholders})", $predicate),
                $codes
            );
        }
    }
};
