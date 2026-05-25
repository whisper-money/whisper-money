<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('categories')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereIn('name', ['Investments', 'Inversiones'])
                        ->where('icon', 'LineChart');
                })->orWhere(function (Builder $query): void {
                    $query->whereIn('name', ['Other investments', 'Otras inversiones'])
                        ->where('icon', 'TrendingUp');
                });
            })
            ->update([
                'type' => CategoryType::Investment->value,
                'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
            ]);

        DB::table('categories')
            ->whereIn('name', ['Savings', 'Ahorros'])
            ->where('icon', 'PiggyBank')
            ->update([
                'type' => CategoryType::Savings->value,
                'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('categories')
            ->where('type', CategoryType::Investment->value)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereIn('name', ['Investments', 'Inversiones'])
                        ->where('icon', 'LineChart');
                })->orWhere(function (Builder $query): void {
                    $query->whereIn('name', ['Other investments', 'Otras inversiones'])
                        ->where('icon', 'TrendingUp');
                });
            })
            ->update([
                'type' => CategoryType::Transfer->value,
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
            ]);

        DB::table('categories')
            ->where('type', CategoryType::Savings->value)
            ->whereIn('name', ['Savings', 'Ahorros'])
            ->where('icon', 'PiggyBank')
            ->update([
                'type' => CategoryType::Transfer->value,
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
            ]);
    }
};
