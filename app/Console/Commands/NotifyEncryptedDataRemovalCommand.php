<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUsersWithLegacyEncryption;
use App\Jobs\SendUpdateEmailJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class NotifyEncryptedDataRemovalCommand extends Command
{
    use FindsUsersWithLegacyEncryption;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'encryption:notify-removal
                            {--dry-run : List affected users without sending anything}
                            {--force : Skip the confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warn users with legacy encrypted data that their account will be deleted unless they sign in within 7 days';

    private const VIEW = 'encrypted-data-removal';

    private const IDENTIFIER = 'encrypted-data-removal';

    private const SUBJECT = 'Action required: sign in to keep your Whisper Money account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $encryptedUsers = $this->usersWithLegacyEncryption()->get();

        // Always report the total scope of legacy encrypted data (soft-deleted users are
        // excluded by the model's default scope). This is the signal for deciding whether
        // the browser-side encryption code can finally be removed, independent of who we
        // actually email below.
        $this->info("{$encryptedUsers->count()} non-deleted user(s) still have encrypted data.");

        $users = $this->excludeBilledUsers($encryptedUsers);

        if ($users->isEmpty()) {
            $this->info('No users to warn.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->renderTable($users);
            $this->info('[dry-run] No emails sent.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Send the deletion warning to {$users->count()} user(s)?", true)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $progressBar = $this->output->createProgressBar($users->count());
        $progressBar->start();

        foreach ($users->values() as $index => $user) {
            // Spread over the 'emails' queue at 50/day to match the existing bulk-email convention.
            SendUpdateEmailJob::dispatch($user, self::VIEW, self::IDENTIFIER, self::SUBJECT)
                ->delay(now()->addDays((int) floor($index / 50)));

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("Queued {$users->count()} warning email(s) to the 'emails' queue (50/day).");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function renderTable($users): void
    {
        $this->table(['Email', 'Last active'], $users->map(fn (User $user) => [
            $user->email,
            $user->last_active_at?->toDateTimeString() ?? 'never',
        ])->all());
    }
}
