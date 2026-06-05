<?php

namespace App\Console\Commands;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Models\BankingConnection;
use Illuminate\Console\Command;

class DisconnectBankingConnectionsCommand extends Command
{
    protected $signature = 'banking:disconnect
        {ids : One or more banking connection IDs, comma-separated}
        {--delete-accounts : Also delete linked accounts, transactions and balances}';

    protected $description = 'Disconnect (soft-delete) banking connections and revoke their session on the provider';

    public function handle(DisconnectBankingConnection $disconnectBankingConnection): int
    {
        $ids = collect(explode(',', (string) $this->argument('ids')))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('No connection IDs provided.');

            return Command::FAILURE;
        }

        $deleteAccounts = $this->option('delete-accounts');

        $connections = BankingConnection::query()
            ->with('accounts')
            ->whereIn('id', $ids)
            ->get();

        $missing = $ids->diff($connections->pluck('id'));

        foreach ($missing as $id) {
            $this->warn("Connection not found: {$id}");
        }

        if ($connections->isEmpty()) {
            $this->error('No matching banking connections found.');

            return Command::FAILURE;
        }

        $disconnected = 0;

        foreach ($connections as $connection) {
            try {
                $disconnectBankingConnection->handle($connection, $deleteAccounts);
                $this->info("Disconnected connection {$connection->id} ({$connection->aspsp_name}).");
                $disconnected++;
            } catch (\Throwable $e) {
                $this->error("Failed to disconnect {$connection->id}: {$e->getMessage()}");
            }
        }

        $this->info("Disconnected {$disconnected} of {$connections->count()} connection(s).");

        return $missing->isEmpty() && $disconnected === $connections->count()
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
