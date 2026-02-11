<?php

namespace App\Jobs;

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBankingConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public BankingConnection $bankingConnection,
    ) {}

    public function uniqueId(): string
    {
        return $this->bankingConnection->id;
    }

    public function handle(TransactionSyncService $transactionSync, BalanceSyncService $balanceSync): void
    {
        $connection = $this->bankingConnection;

        if ($connection->isExpired()) {
            $connection->update(['status' => BankingConnectionStatus::Expired]);
            Log::info('Banking connection expired, skipping sync', ['connection_id' => $connection->id]);

            return;
        }

        if (! $connection->isActive()) {
            return;
        }

        $isFirstSync = ! $connection->last_synced_at;
        $dateFrom = $isFirstSync
            ? now()->subYear()->toDateString()
            : $connection->last_synced_at->toDateString();
        $dateTo = now()->toDateString();
        $strategy = $isFirstSync ? 'longest' : null;

        try {
            foreach ($connection->accounts as $account) {
                if ($account->isLinked()) {
                    $lastTransaction = $account->transactions()
                        ->latest('transaction_date')
                        ->first();

                    $linkedDateFrom = $lastTransaction
                        ? $lastTransaction->transaction_date->toDateString()
                        : $dateFrom;

                    $transactionSync->sync($account, $linkedDateFrom, $dateTo, $strategy, saveDailyBalances: false);
                    $balanceSync->sync($account);
                } else {
                    $transactionSync->sync($account, $dateFrom, $dateTo, $strategy);
                    $balanceSync->sync($account);

                    if ($isFirstSync) {
                        $balanceSync->calculateHistoricalBalances($account);
                    }
                }
            }

            $connection->update([
                'last_synced_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Banking sync failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            $connection->update([
                'status' => BankingConnectionStatus::Error,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
