<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUsersWithLegacyEncryption;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DeleteEncryptedDataAccountsCommand extends Command
{
    use FindsUsersWithLegacyEncryption;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'encryption:delete-accounts
                            {--days=7 : Spare users active within the last N days}
                            {--dry-run : List the accounts that would be deleted without touching them}
                            {--force : Skip the confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Soft-delete and anonymize users who still have legacy encrypted data and did not sign in within the grace window';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $users = $this->excludeBilledUsers(
            $this->usersWithLegacyEncryption()
                ->where('email', '!=', config('app.demo.email'))
                ->where(function (Builder $query) use ($cutoff): void {
                    $query->whereNull('last_active_at')
                        ->orWhere('last_active_at', '<', $cutoff);
                })
                ->get()
        );

        if ($users->isEmpty()) {
            $this->info('No accounts to delete.');

            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} account(s) with encrypted data and no activity in the last {$days} day(s).");

        if ($this->option('dry-run')) {
            $this->table(['Email', 'Last active'], $users->map(fn (User $user) => [
                $user->email,
                $user->last_active_at?->toDateTimeString() ?? 'never',
            ])->all());
            $this->info('[dry-run] No accounts deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Soft-delete and anonymize {$users->count()} account(s)?", false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $progressBar = $this->output->createProgressBar($users->count());
        $progressBar->start();

        foreach ($users as $user) {
            $user->markAsDeleted();
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("Deleted {$users->count()} account(s).");

        return self::SUCCESS;
    }
}
