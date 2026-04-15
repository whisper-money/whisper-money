<?php

namespace App\Actions\OpenBanking;

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use Illuminate\Support\Facades\Log;

class DisconnectBankingConnection
{
    public function __construct(private BankingProviderInterface $provider) {}

    public function handle(BankingConnection $connection, bool $deleteAccounts = false): void
    {
        if ($connection->isEnableBanking() && $connection->session_id && $connection->isActive()) {
            try {
                $this->provider->revokeSession($connection->session_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to revoke EnableBanking session', [
                    'connection_id' => $connection->id,
                    'session_id' => $connection->session_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($deleteAccounts) {
            $connection->accounts->each(function ($account): void {
                $account->transactions()->delete();
                $account->balances()->delete();
                $account->delete();
            });
        } else {
            $connection->accounts()->update([
                'banking_connection_id' => null,
                'external_account_id' => null,
            ]);
        }

        $connection->update(['status' => BankingConnectionStatus::Revoked]);
        $connection->delete();
    }
}
