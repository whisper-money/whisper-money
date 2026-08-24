<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('categories')
            ->whereIn('type', [
                CategoryType::Savings->value,
                CategoryType::Investment->value,
            ])
            ->update([
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('categories')
            ->whereIn('type', [
                CategoryType::Savings->value,
                CategoryType::Investment->value,
            ])
            ->where('cashflow_direction', CategoryCashflowDirection::Outflow->value)
            ->update([
                'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
            ]);
    }
};
