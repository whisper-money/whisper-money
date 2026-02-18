<?php

namespace App\Jobs;

use App\Enums\BankingConnectionStatus;
use App\Mail\BankTransactionsSyncedEmail;
use App\Models\BankingConnection;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\BinanceBalanceSyncService;
use App\Services\Banking\BinanceClient;
use App\Services\Banking\IndexaCapitalBalanceSyncService;
use App\Services\Banking\IndexaCapitalClient;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        if ($connection->isEnableBanking() && $connection->isExpired()) {
            $connection->update(['status' => BankingConnectionStatus::Expired]);
            Log::info('Banking connection expired, skipping sync', ['connection_id' => $connection->id]);

            return;
        }

        if (! $connection->isActive()) {
            return;
        }

        try {
            if ($connection->isIndexaCapital()) {
                $this->syncIndexaCapital($connection);
            } elseif ($connection->isBinance()) {
                $this->syncBinance($connection);
            } else {
                $this->syncEnableBanking($connection, $transactionSync, $balanceSync);
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
                'error_message' => $this->friendlyErrorMessage($e),
            ]);

            throw $e;
        }
    }

    private function syncIndexaCapital(BankingConnection $connection): void
    {
        $client = new IndexaCapitalClient($connection->api_token);
        $syncService = new IndexaCapitalBalanceSyncService;

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            $syncService->sync($account, $client);
        }
    }

    private function syncBinance(BankingConnection $connection): void
    {
        $isFirstSync = ! $connection->last_synced_at;
        $client = new BinanceClient($connection->api_token, $connection->api_secret);
        $syncService = app(BinanceBalanceSyncService::class);

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            if ($isFirstSync) {
                $syncService->syncCurrentBalance($account, $client);
                SyncBinanceHistoricalBalancesJob::dispatch($account)->delay(now()->addSeconds(30));
            } else {
                $syncService->sync($account, $client, isFirstSync: false);
            }
        }
    }

    private function syncEnableBanking(BankingConnection $connection, TransactionSyncService $transactionSync, BalanceSyncService $balanceSync): void
    {
        $isFirstSync = ! $connection->last_synced_at;
        $dateFrom = $isFirstSync
            ? now()->subYear()->toDateString()
            : $connection->last_synced_at->toDateString();
        $dateTo = now()->toDateString();
        $strategy = $isFirstSync ? 'longest' : null;

        $transactionsPerBank = [];

        $connection->load('accounts.bank');

        foreach ($connection->accounts as $account) {
            if ($account->isLinked()) {
                $lastTransaction = $account->transactions()
                    ->latest('transaction_date')
                    ->first();

                $linkedDateFrom = $lastTransaction
                    ? $lastTransaction->transaction_date->toDateString()
                    : $dateFrom;

                $created = $transactionSync->sync($account, $linkedDateFrom, $dateTo, $strategy, saveDailyBalances: false);
                $balanceSync->sync($account);
            } else {
                $created = $transactionSync->sync($account, $dateFrom, $dateTo, $strategy);
                $balanceSync->sync($account);

                if ($isFirstSync) {
                    $balanceSync->calculateHistoricalBalances($account);
                }
            }

            if ($created > 0) {
                $bankName = $account->bank?->name ?? __('Unknown Bank');
                $transactionsPerBank[$bankName] = ($transactionsPerBank[$bankName] ?? 0) + $created;
            }
        }

        $totalTransactions = array_sum($transactionsPerBank);

        if (! $isFirstSync && $totalTransactions > 0) {
            Mail::to($connection->user)->send(new BankTransactionsSyncedEmail(
                $connection->user,
                $totalTransactions,
                $transactionsPerBank,
            ));
        }
    }

    private function friendlyErrorMessage(\Throwable $e): string
    {
        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return match (true) {
                $status === 429 => __('Rate limit exceeded. Please wait a few minutes and try again.'),
                $status === 401 || $status === 403 => __('Authentication failed. Your credentials may have expired or been revoked.'),
                $status >= 500 => __('The provider is experiencing issues. Please try again later.'),
                default => __('Failed to sync with the provider. Please try again later.'),
            };
        }

        return __('An unexpected error occurred during sync. Please try again later.');
    }
}
