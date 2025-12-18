<?php

namespace App\Console\Commands;

use App\Mail\UserLeadInvitation;
use App\Models\UserLead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendUserLeadInvitations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leads:send-invitations {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send invitation emails to all UserLeads with FOUNDER promo code';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $leads = UserLead::query()->get();

        if ($leads->isEmpty()) {
            $this->info('No user leads found in the database.');

            return self::SUCCESS;
        }

        $this->info("Found {$leads->count()} user lead(s).");

        if (! $this->option('force')) {
            if (! $this->confirm("Do you want to send invitation emails to {$leads->count()} lead(s)?", true)) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $this->info('Sending invitation emails...');

        $progressBar = $this->output->createProgressBar($leads->count());
        $progressBar->start();

        $sent = 0;
        foreach ($leads as $lead) {
            Mail::to($lead->email)->send(new UserLeadInvitation($lead));
            $sent++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Successfully queued {$sent} invitation email(s)!");

        return self::SUCCESS;
    }
}
