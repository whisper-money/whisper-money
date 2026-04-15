<?php

namespace App\Console\Commands;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use Illuminate\Console\Command;

class CancelFreeEnableBankingConnectionsCommand extends Command
{
    protected $signature = 'banking:cancel-free-enablebanking';

    protected $description = 'Close Enable Banking connections for free users at month end';

    public function handle(DisconnectBankingConnection $disconnectBankingConnection): int
    {
        $cutoff = now()->subHours(6);

        $query = BankingConnection::query()
            ->with(['user', 'accounts'])
            ->where('provider', 'enablebanking')
            ->where('status', '!=', BankingConnectionStatus::Revoked)
            ->where('created_at', '<=', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No eligible Enable Banking connections found for free users.');

            return Command::SUCCESS;
        }

        $revoked = 0;
        $skipped = 0;

        $query->chunkById(100, function ($connections) use ($disconnectBankingConnection, &$revoked, &$skipped): void {
            foreach ($connections as $connection) {
                if ($connection->user?->hasProPlan()) {
                    $skipped++;

                    continue;
                }

                $disconnectBankingConnection->handle($connection);
                $revoked++;
            }
        }, column: 'id');

        $this->info("Revoked {$revoked} Enable Banking connection(s). Skipped paid users: {$skipped}.");

        return Command::SUCCESS;
    }
}
