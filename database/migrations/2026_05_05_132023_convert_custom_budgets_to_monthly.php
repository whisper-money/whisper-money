<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('budgets')
            ->where('period_type', 'custom')
            ->update([
                'period_type' => 'monthly',
                'period_duration' => null,
                'period_start_day' => 1,
            ]);
    }

    public function down(): void
    {
        // Irreversible: original period_type/duration cannot be recovered.
    }
};
