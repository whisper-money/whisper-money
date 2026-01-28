<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ResendService;
use Illuminate\Console\Command;

class ResendSyncCommand extends Command
{
    protected $signature = 'resend:sync';

    protected $description = 'Sync all users to Resend contacts';

    public function handle(ResendService $resendService): int
    {
        if (! config('services.resend.key')) {
            $this->error('Resend API key not configured.');

            return self::FAILURE;
        }

        $users = User::all();

        if ($users->isEmpty()) {
            $this->info('No users to sync.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$users->count()} users to Resend...");

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        $failed = 0;

        foreach ($users as $user) {
            try {
                $resendService->createContact($user);
            } catch (\Exception $e) {
                $failed++;
                $this->newLine();
                $this->warn("Failed to sync {$user->email}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $synced = $users->count() - $failed;
        $this->info("Synced {$synced} users to Resend.");

        if ($failed > 0) {
            $this->warn("Failed to sync {$failed} users.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
