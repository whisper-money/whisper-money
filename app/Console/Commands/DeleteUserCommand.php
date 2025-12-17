<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
    protected $description = 'Delete a user and all their associated data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return self::FAILURE;
        }

        // Check for active subscriptions
        if ($user->subscribed('default')) {
            $this->error('Cannot delete user with an active subscription. Please cancel the subscription first.');

            return self::FAILURE;
        }

        if (! $this->confirm("Are you sure you want to delete user '{$user->name}' ({$user->email}) and all their data?")) {
            $this->info('Deletion cancelled.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user) {
            // Delete account balances through accounts
            foreach ($user->accounts as $account) {
                $account->balances()->delete();
            }

            // Delete all related data
            $user->encryptedMessage()->delete();
            $user->transactions()->delete();
            $user->accounts()->delete();
            $user->categories()->delete();
            $user->automationRules()->delete();
            $user->labels()->delete();
            $user->mailLogs()->delete();

            // Delete user's banks
            DB::table('banks')->where('user_id', $user->id)->delete();

            // Delete Cashier subscription data if exists
            if ($user->subscriptions()->exists()) {
                $user->subscriptions()->delete();
            }

            // Delete the user
            $user->delete();
        });

        $this->info("User '{$email}' and all associated data have been deleted successfully.");

        return self::SUCCESS;
    }
}
