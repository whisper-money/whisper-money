<?php

namespace App\Console\Commands;

use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Models\User;
use Illuminate\Console\Command;

class SendSubscriptionCancelledEmailCommand extends Command
{
    protected $signature = 'email:subscription-cancelled {emails : Comma-separated list of user emails}';

    protected $description = 'Send the subscription cancellation email to one or more users (the win-back offer if they ever paid, the no-discount note otherwise)';

    public function handle(): int
    {
        $emailsInput = $this->argument('emails');
        $emails = array_map('trim', explode(',', $emailsInput));

        $results = [];

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results[] = [$email, 'Invalid email format'];

                continue;
            }

            $user = User::where('email', $email)->first();

            if (! $user) {
                $results[] = [$email, 'User not found'];

                continue;
            }

            $job = new SendSubscriptionCancelledEmailJob($user);
            $skipReason = $job->ineligibilityReason();

            if ($skipReason !== null) {
                $results[] = [$email, $skipReason];

                continue;
            }

            dispatch($job);
            $results[] = [$email, 'Queued'];
        }

        $this->table(['Email', 'Status'], $results);

        $queuedCount = collect($results)->where(fn ($r) => $r[1] === 'Queued')->count();
        $this->newLine();
        $this->info("Queued {$queuedCount} email(s) for sending.");

        return self::SUCCESS;
    }
}
