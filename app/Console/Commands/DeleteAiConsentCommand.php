<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class DeleteAiConsentCommand extends Command
{
    protected $signature = 'ai:delete-consent {email : The email address of the user whose AI consent to delete}';

    protected $description = "Delete a user's AI consent records, as if they had never granted it";

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return self::FAILURE;
        }

        $deleted = $user->aiConsents()->delete();

        if ($deleted === 0) {
            $this->info("User '{$email}' has no AI consent on record. Nothing to do.");

            return self::SUCCESS;
        }

        $this->info("Deleted AI consent for '{$email}' ({$deleted} record(s)). It's now as if they had never granted it.");

        return self::SUCCESS;
    }
}
