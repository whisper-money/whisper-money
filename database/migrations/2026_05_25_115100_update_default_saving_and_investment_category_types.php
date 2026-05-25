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
        $enabledUserIds = DB::table('users')
            ->where('email', 'victoor89@gmail.com')
            ->pluck('id');

        if ($enabledUserIds->isEmpty()) {
            return;
        }

        DB::table('categories')
            ->whereIn('user_id', $enabledUserIds)
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
            ->whereIn('user_id', $enabledUserIds)
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
        $enabledUserIds = DB::table('users')
            ->where('email', 'victoor89@gmail.com')
            ->pluck('id');

        if ($enabledUserIds->isEmpty()) {
            return;
        }

        DB::table('categories')
            ->whereIn('user_id', $enabledUserIds)
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
            ->whereIn('user_id', $enabledUserIds)
            ->where('type', CategoryType::Savings->value)
            ->whereIn('name', ['Savings', 'Ahorros'])
            ->where('icon', 'PiggyBank')
            ->update([
                'type' => CategoryType::Transfer->value,
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
            ]);
    }
};
