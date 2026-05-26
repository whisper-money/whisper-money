<?php

namespace App\Console\Commands;

use App\Mail\UserLeadReInvitation;
use App\Models\UserLead;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('leads:send-re-invitations
    {--limit=50 : Maximum number of leads to re-invite in this batch}
    {--email= : Re-invite a specific invited lead by email address}
    {--min-days-since-invite=3 : Only include leads invited at least this many days ago}
    {--again : Include leads that were already re-invited}
    {--stats : Show re-invitation signup stats instead of sending emails}
    {--dry-run : Show what would happen without sending emails}
    {--force : Skip confirmation prompt}')]
#[Description('Send follow-up invitation emails to invited leads who have not signed up')]
class SendUserLeadReInvitations extends Command
{
    public function handle(): int
    {
        if ((bool) $this->option('stats')) {
            $this->displayStats();

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $this->error('Limit must be a positive integer.');

            return self::FAILURE;
        }

        $minDaysSinceInvite = max(0, (int) $this->option('min-days-since-invite'));
        $emailFilter = $this->resolveEmailFilter();
        if ($emailFilter === false) {
            return self::FAILURE;
        }

        $leads = $this->pendingReInvitationQuery($minDaysSinceInvite, (bool) $this->option('again'))
            ->when(
                $emailFilter !== null,
                fn (Builder $query) => $query->where('email', $emailFilter),
                fn (Builder $query) => $query->orderBy('invitation_sent_at')->limit($limit),
            )
            ->get();

        if ($leads->isEmpty()) {
            if ($emailFilter !== null) {
                $this->error("No invited lead pending re-invitation found for {$emailFilter}.");

                return self::FAILURE;
            }

            $this->info('No invited leads pending re-invitation found.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Email', 'Invited at', 'Re-invited at', 'Re-invites'],
            $leads->values()->map(fn (UserLead $lead, int $index): array => [
                $index + 1,
                $lead->email,
                $lead->invitation_sent_at?->toDateTimeString(),
                $lead->re_invitation_sent_at?->toDateTimeString() ?? '-',
                $lead->re_invitation_count ?? 0,
            ])->all(),
        );

        if ((bool) $this->option('dry-run')) {
            $this->info('[dry-run] No re-invitation emails sent.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->confirm('Send these re-invitation emails?', true)) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $sent = 0;
        $failed = 0;
        $progressBar = $this->output->createProgressBar($leads->count());
        $progressBar->start();

        foreach ($leads as $lead) {
            try {
                Mail::to($lead->email)->send(new UserLeadReInvitation($lead));

                $lead->forceFill([
                    're_invitation_sent_at' => now(),
                    're_invitation_count' => ((int) $lead->re_invitation_count) + 1,
                ])->save();

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
        $this->info("Queued {$sent} re-invitation email(s)".($failed > 0 ? " ({$failed} failed)" : '').'.');
        $this->displayStats();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Builder<UserLead> */
    private function pendingReInvitationQuery(int $minDaysSinceInvite, bool $includeAlreadyReInvited): Builder
    {
        return UserLead::query()
            ->whereNotNull('invitation_sent_at')
            ->whereDoesntHave('signedUpUser')
            ->when(! $includeAlreadyReInvited, fn (Builder $query) => $query->whereNull('re_invitation_sent_at'))
            ->when(
                $minDaysSinceInvite > 0,
                fn (Builder $query) => $query->where('invitation_sent_at', '<=', now()->subDays($minDaysSinceInvite)),
            );
    }

    private function displayStats(): void
    {
        $reInvited = UserLead::query()
            ->whereNotNull('re_invitation_sent_at')
            ->count();

        $signedUpAfterReInvite = UserLead::query()
            ->whereNotNull('re_invitation_sent_at')
            ->whereHas('signedUpUser', fn (Builder $query) => $query->whereColumn('users.created_at', '>=', 'user_leads.re_invitation_sent_at'))
            ->count();

        $rate = $reInvited > 0 ? round(($signedUpAfterReInvite / $reInvited) * 100, 2) : 0.0;

        $this->table(['Metric', 'Value'], [
            ['Re-invited leads', $reInvited],
            ['Re-invited leads signed up', $signedUpAfterReInvite],
            ['Success rate', $rate.'%'],
        ]);
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
}
