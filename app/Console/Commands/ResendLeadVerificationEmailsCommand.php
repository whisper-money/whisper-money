<?php

namespace App\Console\Commands;

use App\Models\UserLead;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:resend-verification-emails {--dry-run : Show what would happen without dispatching emails}')]
#[Description('Resend verification emails to leads that have not yet verified their email address')]
class ResendLeadVerificationEmailsCommand extends Command
{
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN — no emails will be dispatched.');
            $this->newLine();
        }

        $leads = UserLead::query()->whereNull('email_verified_at')->get();

        if ($leads->isEmpty()) {
            $this->info('No unverified leads found.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        $progressBar = $this->output->createProgressBar($leads->count());
        $progressBar->start();

        foreach ($leads as $lead) {
            if (! $isDryRun) {
                $lead->sendEmailVerificationNotification();
            }

            $dispatched++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->table(
            ['Action', 'Count'],
            [
                ['dispatched'.($isDryRun ? ' (dry run)' : ''), $dispatched],
            ]
        );

        return self::SUCCESS;
    }
}
