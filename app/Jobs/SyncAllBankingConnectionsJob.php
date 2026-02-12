<?php

namespace App\Jobs;

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAllBankingConnectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        BankingConnection::query()
            ->where('status', BankingConnectionStatus::Active)
            ->where(function ($query) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>', now());
            })
            ->each(function (BankingConnection $connection) {
                SyncBankingConnectionJob::dispatch($connection);
            });
    }
}
