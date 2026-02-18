<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Banking\BinanceBalanceSyncService;
use App\Services\Banking\BinanceClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBinanceHistoricalBalancesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 600;

    public function __construct(
        public Account $account,
    ) {}

    public function uniqueId(): string
    {
        return 'binance-historical-'.$this->account->id;
    }

    public function handle(BinanceBalanceSyncService $syncService): void
    {
        $connection = $this->account->bankingConnection;

        if (! $connection || ! $connection->isBinance()) {
            return;
        }

        $client = new BinanceClient($connection->api_token, $connection->api_secret);

        $syncService->syncHistoricalBalances($this->account, $client, isFirstSync: true);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Binance historical balance sync failed', [
            'account_id' => $this->account->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
