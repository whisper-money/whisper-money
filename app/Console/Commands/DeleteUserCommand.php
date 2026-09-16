<?php

namespace App\Console\Commands;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Cashier\Subscription;

class DeleteUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:delete
                            {email : The email address of the user to delete}
                            {--force : Skip the confirmation prompts}';

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

        $subscription = $user->collectableSubscription();
        $enableBankingConnections = $user->bankingConnections()
            ->with('accounts')
            ->where('provider', BankingProvider::EnableBanking)
            ->get();

        if (! $this->confirmDeletion($user, $subscription, $enableBankingConnections)) {
            $this->info('Deletion cancelled. Pass --force to confirm without being prompted.');

            return self::SUCCESS;
        }

        if ($subscription) {
            $this->cancelSubscription($user, $subscription);
            $this->info("Cancelled Stripe subscription for '{$user->email}'.");
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

    /**
     * Ask about everything the deletion takes with it, unless --force answers yes to all of it.
     *
     * @param  Collection<int, BankingConnection>  $enableBankingConnections
     */
    private function confirmDeletion(User $user, ?Subscription $subscription, Collection $enableBankingConnections): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->confirm("Are you sure you want to mark user '{$user->name}' ({$user->email}) as deleted? Their data will be preserved.")) {
            return false;
        }

        if ($subscription && ! $this->confirm("User '{$user->email}' has a chargeable Stripe subscription ({$subscription->stripe_status}). Cancel it before deleting the user?")) {
            return false;
        }

        return $enableBankingConnections->isEmpty()
            || $this->confirm("User '{$user->email}' has {$enableBankingConnections->count()} Enable Banking connection(s). Revoke them and keep linked accounts as manual accounts?");
    }

    private function cancelSubscription(User $user, Subscription $subscription): void
    {
        if ($user->hasStripeId() && ! $user->hasSeededSubscription()) {
            $subscription->cancelNow();

            return;
        }

        $subscription->markAsCanceled();
    }
}
