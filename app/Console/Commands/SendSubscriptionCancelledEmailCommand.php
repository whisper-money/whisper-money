<?php

namespace App\Console\Commands;

use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Models\User;
use Illuminate\Console\Command;

class SendSubscriptionCancelledEmailCommand extends Command
{
    protected $signature = 'email:subscription-cancelled {emails : Comma-separated list of user emails}';

    protected $description = 'Send subscription cancellation email to one or more users';

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

            SendSubscriptionCancelledEmailJob::dispatch($user);
            $results[] = [$email, 'Queued'];
        }

        $this->table(['Email', 'Status'], $results);

        $queuedCount = collect($results)->where(fn ($r) => $r[1] === 'Queued')->count();
        $this->newLine();
        $this->info("Queued {$queuedCount} email(s) for sending.");

        return self::SUCCESS;
    }
}
