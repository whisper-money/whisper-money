<?php

namespace App\Console\Commands;

use App\Models\UserLead;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('leads:retry-failed-jobs {--dry-run : Show what would happen without making changes}')]
#[Description('Selectively retry failed lead email jobs, forgetting stale ones whose lead was deleted or is not yet verified')]
class RetryLeadFailedJobsCommand extends Command
{
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be made.');
            $this->newLine();
        }

        $failedJobs = DB::table('failed_jobs')
            ->where('queue', 'emails')
            ->get();

        if ($failedJobs->isEmpty()) {
            $this->info('No failed email jobs found.');

            return self::SUCCESS;
        }

        $retried = 0;
        $forgotten = 0;
        $skipped = 0;

        $progressBar = $this->output->createProgressBar($failedJobs->count());
        $progressBar->start();

        foreach ($failedJobs as $job) {
            $payload = json_decode($job->payload, true);
            $displayName = $payload['displayName'] ?? '';
            $command = $payload['data']['command'] ?? '';

            $leadId = $this->extractLeadId($command);

            if ($leadId === null) {
                $skipped++;
                $progressBar->advance();

                continue;
            }

            $lead = UserLead::find($leadId);
            $action = $this->determineAction($lead, $displayName);

            if ($action === 'retry') {
                $retried++;
                if (! $isDryRun) {
                    $this->callSilently('queue:retry', ['id' => [$job->uuid]]);
                }
            } else {
                $forgotten++;
                if (! $isDryRun) {
                    $this->callSilently('queue:forget', ['id' => [$job->uuid]]);
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->table(
            ['Action', 'Count'],
            [
                ['retried'.($isDryRun ? ' (dry run)' : ''), $retried],
                ['forgotten'.($isDryRun ? ' (dry run)' : ''), $forgotten],
                ['skipped (no lead ID found)', $skipped],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Extract the UserLead UUID from a serialized job payload command string.
     *
     * Handles both queued mailables (id stored as a single string) and
     * queued notifications (id stored as a single-element array).
     */
    private function extractLeadId(string $command): ?string
    {
        // Mail jobs store the lead as a single string ID
        if (preg_match('/App\\\\Models\\\\UserLead";s:2:"id";s:\d+:"([0-9a-f-]{36})"/', $command, $matches)) {
            return $matches[1];
        }

        // Notification jobs store notifiables as an array of IDs
        if (preg_match('/App\\\\Models\\\\UserLead";s:2:"id";a:\d+:\{i:0;s:\d+:"([0-9a-f-]{36})"/', $command, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Decide whether to retry or forget a failed job based on lead state.
     *
     * Rules:
     * - Lead deleted          → forget (DDoS cleanup or similar)
     * - Lead verified         → retry (Resend rate limit was the issue)
     * - Lead unverified + VerifyUserLeadEmailNotification → retry (still needs the verification email)
     * - Lead unverified + anything else → forget (waitlist emails to unverified leads are meaningless)
     */
    private function determineAction(?UserLead $lead, string $displayName): string
    {
        if ($lead === null) {
            return 'forget';
        }

        if ($lead->hasVerifiedEmail()) {
            return 'retry';
        }

        if (str_contains($displayName, 'VerifyUserLeadEmailNotification')) {
            return 'retry';
        }

        return 'forget';
    }
}
