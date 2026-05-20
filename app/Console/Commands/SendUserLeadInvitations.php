<?php

namespace App\Console\Commands;

use App\Enums\LeadCohort;
use App\Mail\UserLeadInvitation;
use App\Models\UserLead;
use App\Services\LeadCohortResolver;
use App\Services\LeadPromoCodeAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendUserLeadInvitations extends Command
{
    protected $signature = 'leads:send-invitations
        {--limit=50 : Maximum number of leads to invite in this batch}
        {--email= : Invite a specific pending lead by email address}
        {--cohort= : Restrict to a single cohort (founder, founder_referrer, early_bird, waitlist)}
        {--dry-run : Show what would happen without sending or creating Stripe codes}
        {--force : Skip confirmation prompt}';

    protected $description = 'Send launch invitation emails to the next batch of waitlist leads';

    protected $aliases = [
        'leads:send-invite',
        'leads:send-invites',
    ];

    public function handle(LeadCohortResolver $resolver, LeadPromoCodeAllocator $allocator): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $this->error('Limit must be a positive integer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $emailFilter = $this->resolveEmailFilter();
        $cohortFilter = $this->resolveCohortFilter();

        if ($emailFilter === false || $cohortFilter === false) {
            return self::FAILURE;
        }

        $candidates = UserLead::query()
            ->whereNotNull('position')
            ->where('position', '>', 0)
            ->whereNull('invitation_sent_at')
            ->when(
                $emailFilter !== null,
                fn ($query) => $query->where('email', $emailFilter),
                fn ($query) => $query->orderBy('position')->limit($limit * 5), // over-fetch when filtering by cohort
            )
            ->get();

        if ($candidates->isEmpty()) {
            if ($emailFilter !== null) {
                $this->error("No pending lead found for {$emailFilter}.");

                return self::FAILURE;
            }

            $this->info('No pending leads found.');

            return self::SUCCESS;
        }

        $plan = [];
        foreach ($candidates as $lead) {
            $cohort = $resolver->resolve($lead);
            if ($cohort === null) {
                continue;
            }

            if ($cohortFilter !== null && $cohort !== $cohortFilter) {
                continue;
            }

            $plan[] = [$lead, $cohort];

            if (count($plan) >= $limit) {
                break;
            }
        }

        if ($plan === []) {
            $this->info('No leads matched the requested filters.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Position', 'Email', 'Cohort'],
            array_map(
                fn (array $row, int $i): array => [
                    $i + 1,
                    $row[0]->position,
                    $row[0]->email,
                    $row[1]->value,
                ],
                $plan,
                array_keys($plan),
            ),
        );

        if ($dryRun) {
            $this->info('[dry-run] No emails sent.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->confirm('Send these invitation emails?', true)) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $sent = 0;
        $failed = 0;
        $progressBar = $this->output->createProgressBar(count($plan));
        $progressBar->start();

        foreach ($plan as [$lead, $cohort]) {
            try {
                /** @var UserLead $lead */
                /** @var LeadCohort $cohort */
                $lead->cohort = $cohort;
                $allocator->ensureCodes($lead, $cohort);

                Mail::to($lead->email)->send(new UserLeadInvitation($lead->fresh(), $cohort));

                $lead->forceFill(['invitation_sent_at' => now()])->save();
                $sent++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Failed for {$lead->email}: {$exception->getMessage()}");
                report($exception);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Queued {$sent} invitation email(s)".($failed > 0 ? " ({$failed} failed)" : '').'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveEmailFilter(): string|false|null
    {
        $email = $this->option('email');

        if ($email === null || $email === '') {
            return null;
        }

        $email = strtolower(trim((string) $email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email `{$email}`.");

            return false;
        }

        return $email;
    }

    private function resolveCohortFilter(): LeadCohort|false|null
    {
        $cohort = $this->option('cohort');

        if ($cohort === null || $cohort === '') {
            return null;
        }

        $resolved = LeadCohort::tryFrom((string) $cohort);

        if ($resolved === null) {
            $this->error("Unknown cohort `{$cohort}`. Valid values: founder, founder_referrer, early_bird, waitlist.");

            return false;
        }

        return $resolved;
    }
}
