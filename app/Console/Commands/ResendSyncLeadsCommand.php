<?php

namespace App\Console\Commands;

use App\Models\UserLead;
use App\Services\ResendService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('resend:sync-leads {--since=24 : Sync leads created within the last N hours. Use 0 to sync all.}')]
#[Description('Sync user leads to the Resend leads segment (last 24h by default)')]
class ResendSyncLeadsCommand extends Command
{
    public function handle(ResendService $resendService): int
    {
        $since = (int) $this->option('since');

        if (! config('services.resend.key')) {
            $this->error('Resend API key not configured.');

            return self::FAILURE;
        }

        if (! config('services.resend.leads_segment_id')) {
            $this->error('Resend leads segment ID not configured.');

            return self::FAILURE;
        }

        $leads = UserLead::query()
            ->whereNotNull('email_verified_at')
            ->when($since > 0, fn ($query) => $query->where('created_at', '>=', now()->subHours($since)))
            ->get();

        if ($leads->isEmpty()) {
            $this->info('No user leads to sync.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$leads->count()} user leads to Resend...");

        $progressBar = $this->output->createProgressBar($leads->count());
        $progressBar->start();

        $failed = 0;

        foreach ($leads as $lead) {
            try {
                $resendService->syncLead($lead);
            } catch (\Exception $exception) {
                $failed++;
                $this->newLine();
                $this->warn("Failed to sync {$lead->email}: {$exception->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $synced = $leads->count() - $failed;
        $this->info("Synced {$synced} user leads to Resend.");

        if ($failed > 0) {
            $this->warn("Failed to sync {$failed} user leads.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
