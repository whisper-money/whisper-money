<?php

namespace App\Console\Commands;

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Support command for the live Enable Banking e2e script
 * (tests/Browser/live/connect-bank.mjs). It seeds deterministic users, inspects the
 * resulting connection, and can force a connection into the expired state so the
 * reconnect flow can be exercised. Output is JSON so the script can parse it.
 *
 * Restricted to local/testing environments — it creates passwordful users and fake
 * subscriptions and must never run against production data.
 */
class E2eBankingFixtureCommand extends Command
{
    protected $signature = 'e2e:banking-fixture {action : seed|inspect|expire} {email? : target user email for inspect/expire}';

    protected $description = 'Seed/inspect fixtures for the live Enable Banking e2e script (local only).';

    private const ONBOARDING_EMAIL = 'e2e-onboarding@example.test';

    private const SETTINGS_EMAIL = 'e2e-settings@example.test';

    private const PASSWORD = 'password';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('e2e:banking-fixture is only available in local/testing environments.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'seed' => $this->seed(),
            'inspect' => $this->inspect(),
            'expire' => $this->expire(),
            default => $this->invalidAction(),
        };
    }

    private function seed(): int
    {
        $onboarding = $this->resetUser(self::ONBOARDING_EMAIL, onboarded: false, pro: false);
        $settings = $this->resetUser(self::SETTINGS_EMAIL, onboarded: true, pro: true);

        $this->line((string) json_encode([
            'onboarding' => ['email' => $onboarding->email, 'password' => self::PASSWORD],
            'settings' => ['email' => $settings->email, 'password' => self::PASSWORD],
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function inspect(): int
    {
        $connection = $this->latestConnection();

        if (! $connection) {
            $this->line((string) json_encode(['connection' => null]));

            return self::SUCCESS;
        }

        $accountIds = $connection->accounts()->pluck('id');

        $this->line((string) json_encode([
            'connection' => [
                'id' => $connection->id,
                'status' => $connection->status->value,
                'aspsp_name' => $connection->aspsp_name,
                'session_id' => $connection->session_id !== null,
                'valid_until' => $connection->valid_until?->toIso8601String(),
                'accounts_count' => $accountIds->count(),
                'accounts_without_external_id' => $connection->accounts()->whereNull('external_account_id')->count(),
                'transactions_count' => Transaction::whereIn('account_id', $accountIds)->count(),
            ],
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function expire(): int
    {
        $connection = $this->latestConnection();

        if (! $connection) {
            $this->error('No connection found for '.$this->argument('email'));

            return self::FAILURE;
        }

        $connection->update([
            'status' => BankingConnectionStatus::Expired,
            'valid_until' => now()->subDay(),
        ]);

        $this->line((string) json_encode(['expired' => $connection->id]));

        return self::SUCCESS;
    }

    private function resetUser(string $email, bool $onboarded, bool $pro): User
    {
        // Reuse the user across runs (the model soft-deletes, so recreating the same
        // email would collide). Clear everything the flows produce so each run starts
        // from a clean slate.
        $user = User::withTrashed()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email]);

        $user->accounts()->forceDelete();
        $user->bankingConnections()->forceDelete();
        $user->subscriptions()->delete();

        $user->restore();
        $user->forceFill([
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
            'onboarded_at' => $onboarded ? now() : null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if ($pro) {
            $user->subscriptions()->create([
                'type' => 'default',
                'stripe_id' => 'sub_e2e_'.$user->id,
                'stripe_status' => 'active',
                'stripe_price' => 'price_e2e',
                'quantity' => 1,
            ]);
        }

        return $user;
    }

    private function latestConnection(): ?BankingConnection
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            return null;
        }

        return $user->bankingConnections()->latest()->first();
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action. Use one of: seed, inspect, expire.');

        return self::FAILURE;
    }
}
