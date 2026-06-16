<?php

namespace App\Console\Commands;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Enums\BankingProvider;
use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Cashier\Subscription;

class DeleteUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:delete {email : The email address of the user to delete}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark a user as deleted while preserving their data';

    /**
     * Execute the console command.
     */
    public function handle(DisconnectBankingConnection $disconnectBankingConnection): int
    {
        $email = $this->argument('email');

        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return self::FAILURE;
        }

        if ($user->trashed()) {
            $this->info("User '{$email}' is already marked as deleted.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Are you sure you want to mark user '{$user->name}' ({$user->email}) as deleted? Their data will be preserved.")) {
            $this->info('Deletion cancelled.');

            return self::SUCCESS;
        }

        $subscription = $this->activeSubscription($user);
        $enableBankingConnections = $user->bankingConnections()
            ->with('accounts')
            ->where('provider', BankingProvider::EnableBanking)
            ->get();

        if ($subscription && ! $this->confirm("User '{$user->email}' has an active Stripe subscription. Cancel it before deleting the user?")) {
            $this->info('Deletion cancelled.');

            return self::SUCCESS;
        }

        if ($enableBankingConnections->isNotEmpty() && ! $this->confirm("User '{$user->email}' has {$enableBankingConnections->count()} Enable Banking connection(s). Revoke them and keep linked accounts as manual accounts?")) {
            $this->info('Deletion cancelled.');

            return self::SUCCESS;
        }

        if ($subscription) {
            $this->cancelSubscription($user, $subscription);
            $this->info("Cancelled active Stripe subscription for '{$user->email}'.");
        }

        foreach ($enableBankingConnections as $connection) {
            $disconnectBankingConnection->handle($connection, deleteAccounts: false);
        }

        if ($enableBankingConnections->isNotEmpty()) {
            $this->info("Revoked {$enableBankingConnections->count()} Enable Banking connection(s) for '{$user->email}'.");
        }

        $user->markAsDeleted();

        $this->info("User '{$email}' has been marked as deleted. Their data remains in the database.");

        return self::SUCCESS;
    }

    private function activeSubscription(User $user): ?Subscription
    {
        $subscription = $user->subscription('default');

        if (! $subscription || ! $subscription->valid()) {
            return null;
        }

        return $subscription;
    }

    private function cancelSubscription(User $user, Subscription $subscription): void
    {
        if ($user->hasStripeId()) {
            $subscription->cancelNow();

            return;
        }

        $subscription->markAsCanceled();
    }
}
