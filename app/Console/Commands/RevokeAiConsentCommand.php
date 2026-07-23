<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class RevokeAiConsentCommand extends Command
{
    protected $signature = 'ai:revoke-consent {email : The email address of the user whose AI consent to revoke}';

    protected $description = "Revoke a user's AI consent so AI stops being used for their account";

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return self::FAILURE;
        }

        if (! $user->hasActiveAiConsent()) {
            $this->info("User '{$email}' has no active AI consent. Nothing to do.");

            return self::SUCCESS;
        }

        $user->revokeAiConsent();

        $this->info("Revoked AI consent for '{$email}'. AI won't be used for their account unless they re-enable it from Settings.");

        return self::SUCCESS;
    }
}
