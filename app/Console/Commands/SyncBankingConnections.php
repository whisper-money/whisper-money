<?php

namespace App\Console\Commands;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Jobs\SyncAllBankingConnectionsJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Console\Command;

class SyncBankingConnections extends Command
{
    protected $signature = 'banking:sync
        {--user= : Filter by user email address}
        {--connection= : Filter by banking connection ID}
        {--sync : Run synchronously instead of dispatching to the queue}
        {--full : Force a full sync instead of incremental}';

    protected $description = 'Sync transactions and balances for all active banking connections';

    public function handle(): int
    {
        $userEmail = $this->option('user');
        $connectionId = $this->option('connection');
        $sync = $this->option('sync');
        $fullSync = $this->option('full');

        if (! $userEmail && ! $connectionId) {
            if ($sync) {
                $this->error('The --sync option requires --user and/or --connection filters.');

                return Command::FAILURE;
            }

            SyncAllBankingConnectionsJob::dispatch($fullSync);

            $this->info('Banking sync jobs dispatched for all active connections.');

            return Command::SUCCESS;
        }

        $query = BankingConnection::query()
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

        $connections->each(function (BankingConnection $connection) use ($sync, $fullSync) {
            if ($sync) {
                $this->info("Syncing {$connection->provider->value} connection {$connection->id}...");
                SyncBankingConnectionJob::dispatchSync($connection, $fullSync);
                $this->info("Finished syncing {$connection->provider->value} connection {$connection->id}.");
            } else {
                SyncBankingConnectionJob::dispatch($connection, $fullSync);
            }
        });

        $verb = $sync ? 'synced' : 'dispatched';
        $this->info("Banking sync jobs {$verb} for {$connections->count()} connection(s).");

        return Command::SUCCESS;
    }
}
