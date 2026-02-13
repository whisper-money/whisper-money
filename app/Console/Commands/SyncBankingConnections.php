<?php

namespace App\Console\Commands;

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncAllBankingConnectionsJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Console\Command;

class SyncBankingConnections extends Command
{
    protected $signature = 'banking:sync
        {--user= : Filter by user email address}
        {--connection= : Filter by banking connection ID}';

    protected $description = 'Sync transactions and balances for all active banking connections';

    public function handle(): int
    {
        $userEmail = $this->option('user');
        $connectionId = $this->option('connection');

        if (! $userEmail && ! $connectionId) {
            SyncAllBankingConnectionsJob::dispatch();

            $this->info('Banking sync jobs dispatched for all active connections.');

            return Command::SUCCESS;
        }

        $query = BankingConnection::query()
            ->where('status', BankingConnectionStatus::Active)
            ->where(function ($query) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>', now());
            });

        if ($connectionId) {
            $query->where('id', $connectionId);
        }

        if ($userEmail) {
            $user = User::query()->where('email', $userEmail)->first();

            if (! $user) {
                $this->error("User with email '{$userEmail}' not found.");

                return Command::FAILURE;
            }

            $query->where('user_id', $user->id);
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->warn('No active banking connections found matching the given filters.');

            return Command::SUCCESS;
        }

        $connections->each(function (BankingConnection $connection) {
            SyncBankingConnectionJob::dispatch($connection);
        });

        $this->info("Banking sync jobs dispatched for {$connections->count()} connection(s).");

        return Command::SUCCESS;
    }
}
