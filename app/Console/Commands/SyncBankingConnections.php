<?php

namespace App\Console\Commands;

use App\Jobs\SyncAllBankingConnectionsJob;
use Illuminate\Console\Command;

class SyncBankingConnections extends Command
{
    protected $signature = 'banking:sync';

    protected $description = 'Sync transactions and balances for all active banking connections';

    public function handle(): int
    {
        SyncAllBankingConnectionsJob::dispatch();

        $this->info('Banking sync jobs dispatched.');

        return Command::SUCCESS;
    }
}
