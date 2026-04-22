<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

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
    public function handle(): int
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

        if ($user->subscribed('default')) {
            $this->error('Cannot delete user with an active subscription. Please cancel the subscription first.');

            return self::FAILURE;
        }

        if (! $this->confirm("Are you sure you want to mark user '{$user->name}' ({$user->email}) as deleted? Their data will be preserved.")) {
            $this->info('Deletion cancelled.');

            return self::SUCCESS;
        }

        $user->markAsDeleted();

        $this->info("User '{$email}' has been marked as deleted. Their data remains in the database.");

        return self::SUCCESS;
    }
}
