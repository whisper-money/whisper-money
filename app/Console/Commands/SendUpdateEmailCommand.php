<?php

namespace App\Console\Commands;

use App\Jobs\SendUpdateEmailJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SendUpdateEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:update
                            {view : The view name (e.g., "jan-2026-updates")}
                            {identifier? : The tracking identifier (defaults to view name)}
                            {--subject= : Custom email subject (default: "Update from Whisper Money")}
                            {--exclude-demo : Exclude the demo account}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send update emails to all users using a markdown view template';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $viewName = $this->argument('view');
        $identifier = $this->argument('identifier') ?? $viewName;
        $subject = $this->option('subject') ?? 'Update from Whisper Money';

        if ($this->option('force')) {
            $identifier = $identifier.'_force_'.time();
        }

        $viewPath = resource_path("views/mail/updates/{$viewName}.blade.php");

        if (! File::exists($viewPath)) {
            $this->error("View file not found: {$viewPath}");
            $this->info("Please create the view at: resources/views/mail/updates/{$viewName}.blade.php");

            return self::FAILURE;
        }

        $users = User::query()->get();

        if ($this->option('exclude-demo')) {
            $users = $users->filter(fn (User $user) => ! $user->isDemoAccount());
        }

        if ($users->isEmpty()) {
            $this->info('No users found in the database.');

            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} user(s).");

        if (! $this->option('force')) {
            if (! $this->confirm("About to send '{$identifier}' email to {$users->count()} user(s). Continue?", true)) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $this->info('Queueing update emails...');
        $this->info('Rate limit: 50 emails per day');

        $progressBar = $this->output->createProgressBar($users->count());
        $progressBar->start();

        $queued = 0;
        foreach ($users as $index => $user) {
            // Add delay of +1 day for every 50 emails
            $delayDays = (int) floor($index / 50);
            $delay = now()->addDays($delayDays);

            SendUpdateEmailJob::dispatch($user, $viewName, $identifier, $subject)
                ->delay($delay);

            $queued++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($queued > 50) {
            $totalDays = (int) floor(($queued - 1) / 50);
            $this->info("Successfully queued {$queued} update email(s) to the 'emails' queue!");
            $this->info("Emails will be sent over {$totalDays} day(s) (50 emails per day)");
        } else {
            $this->info("Successfully queued {$queued} update email(s) to the 'emails' queue!");
        }

        return self::SUCCESS;
    }
}
