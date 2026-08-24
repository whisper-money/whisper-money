<?php

use App\Services\Banking\TransactionCounterpartyExtractor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('creditor_name')->nullable()->after('raw_data');
            $table->string('debtor_name')->nullable()->after('creditor_name');
            $table->index('creditor_name');
            $table->index('debtor_name');
        });

        DB::table('transactions')
            ->select(['id', 'raw_data'])
            ->whereNotNull('raw_data')
            ->orderBy('id')
            ->chunk(500, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    if (! is_string($transaction->raw_data)) {
                        continue;
                    }

                    $rawData = json_decode($transaction->raw_data, true);

                    if (! is_array($rawData)) {
                        continue;
                    }

                    $counterparties = TransactionCounterpartyExtractor::fromPayload($rawData);

                    if ($counterparties['creditor_name'] === null && $counterparties['debtor_name'] === null) {
                        continue;
                    }

                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->update($counterparties);
                }
            });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['creditor_name']);
            $table->dropIndex(['debtor_name']);
            $table->dropColumn(['creditor_name', 'debtor_name']);
        });
    }
};
