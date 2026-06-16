<?php

namespace App\Jobs;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAllBankingConnectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public bool $fullSync = false,
    ) {}

    public function handle(): void
    {
        BankingConnection::query()
            ->whereHas('user')
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where(function ($query) {
                        $query->where('status', BankingConnectionStatus::Active)
                            ->orWhere(function ($query) {
                                $query->where('status', BankingConnectionStatus::Error)
                                    ->where('consecutive_sync_failures', '<', SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES);
                            });
                    })->where(function ($query) {
                        $query->whereNull('valid_until')
                            ->orWhere('valid_until', '>', now());
                    });
                })->orWhere(function ($query) {
                    $query->where('provider', BankingProvider::EnableBanking)
                        ->where('status', BankingConnectionStatus::Active)
                        ->whereNotNull('valid_until')
                        ->where('valid_until', '<=', now());
                });
            })
            ->where(function ($query) {
                $query->whereNull('rate_limited_until')
                    ->orWhere('rate_limited_until', '<=', now());
            })
            ->each(function (BankingConnection $connection) {
                SyncBankingConnectionJob::dispatch($connection, $this->fullSync);
            });
    }
}
