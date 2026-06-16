<?php

namespace App\Console\Commands;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Mail\EnableBankingConnectionsCancelledEmail;
use App\Models\BankingConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CancelFreeEnableBankingConnectionsCommand extends Command
{
    protected $signature = 'banking:cancel-free-enablebanking';

    protected $description = 'Close Enable Banking connections for free users at month end';

    public function handle(DisconnectBankingConnection $disconnectBankingConnection): int
    {
        $cutoff = now()->subHours(6);

        $connections = BankingConnection::query()
            ->with(['user', 'accounts'])
            ->whereHas('user')
            ->where('provider', BankingProvider::EnableBanking)
            ->where('status', '!=', BankingConnectionStatus::Revoked)
            ->where('created_at', '<=', $cutoff)
            ->get();

        $count = $connections->count();

        if ($count === 0) {
            $this->info('No eligible Enable Banking connections found for free users.');

            return Command::SUCCESS;
        }

        $revoked = 0;
        $skipped = 0;

        $connections
            ->groupBy('user_id')
            ->each(function ($userConnections) use ($disconnectBankingConnection, &$revoked, &$skipped): void {
                $user = $userConnections->first()?->user;

                if (! $user) {
                    return;
                }

                if ($user->hasProPlan()) {
                    $skipped += $userConnections->count();

                    return;
                }

                foreach ($userConnections as $connection) {
                    $disconnectBankingConnection->handle($connection);
                    $revoked++;
                }

                if ($user->canReceiveEmails()) {
                    Mail::to($user)->send(new EnableBankingConnectionsCancelledEmail(
                        $user,
                        $userConnections->count(),
                    ));
                }
            });

        $this->info("Revoked {$revoked} Enable Banking connection(s). Skipped paid users: {$skipped}.");

        return Command::SUCCESS;
    }
}
