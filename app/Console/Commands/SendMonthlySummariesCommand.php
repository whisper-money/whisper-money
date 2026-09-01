<?php

namespace App\Console\Commands;

use App\Enums\DripEmailType;
use App\Features\MonthlySummaries;
use App\Jobs\Drip\SendMonthlySummaryEmailJob;
use App\Jobs\Drip\SendMonthlySummaryReminderEmailJob;
use App\Models\User;
use App\Services\MonthlySummary\Readiness;
use App\Services\MonthlySummary\Summaries;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Pennant\Feature;

/**
 * Queues the monthly report, and the nudge for whoever is not ready for one.
 *
 * Runs hourly through the window rather than once on a fixed morning, because
 * over a thousand onboarded users are in American timezones where a Madrid 9am
 * is the middle of the night — and this is the one email whose whole point is
 * that it gets opened. Each pass picks the readers for whom it is now the send
 * hour, locally.
 *
 * The window itself: the report goes out on the 3rd, once the 1st-of-month jobs
 * that settle loan and real-estate balances have run and any overnight bank sync
 * is in. Anyone whose month is still moving is retried daily, and on the last day
 * the report goes out with whatever is there, saying as much.
 */
class SendMonthlySummariesCommand extends Command
{
    protected $signature = 'email:monthly-summary
        {--user= : Only this user, ignoring the send hour}
        {--month= : The closed month to report, as YYYY-MM (defaults to last month)}
        {--dry-run : Report what would be queued without queueing anything}';

    protected $description = 'Queue the monthly summary email, and the reminder for users whose month is not ready yet';

    public function __construct(
        private Readiness $readiness,
        private Summaries $summaries,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('mail.drip_emails_enabled')) {
            $this->info('Drip emails are disabled. Nothing to do.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($this->timezonesDueNow() as $timezone) {
            $queued += $this->processTimezone($timezone);
        }

        $this->info("Queued {$queued} monthly summary email(s).");

        return self::SUCCESS;
    }

    /**
     * The timezones for which it is the send hour, on a day inside the window.
     *
     * A dozen or so distinct timezones are in play, so asking each one what time
     * it is beats trying to express local time in SQL.
     *
     * @return list<string>
     */
    private function timezonesDueNow(): array
    {
        if ($this->option('user') !== null) {
            return [$this->fallbackTimezone()];
        }

        return array_values(array_filter(
            $this->knownTimezones(),
            fn (string $timezone): bool => $this->isDue($timezone),
        ));
    }

    /**
     * @return list<string>
     */
    private function knownTimezones(): array
    {
        $stored = User::query()
            ->whereNotNull('timezone')
            ->distinct()
            ->pluck('timezone')
            ->filter(fn (?string $timezone): bool => $timezone !== null && in_array($timezone, timezone_identifiers_list(), true))
            ->all();

        return array_values(array_unique([...$stored, $this->fallbackTimezone()]));
    }

    private function isDue(string $timezone): bool
    {
        $local = now()->setTimezone($timezone);

        return $local->hour === (int) config('monthly_summary.send_hour')
            && $local->day >= (int) config('monthly_summary.first_day')
            && $local->day <= (int) config('monthly_summary.last_day');
    }

    private function processTimezone(string $timezone): int
    {
        $queued = 0;

        $this->candidates($timezone)->chunkById(100, function (Collection $users) use ($timezone, &$queued): void {
            foreach ($users as $user) {
                $queued += $this->process($user, $timezone) ? 1 : 0;
            }
        });

        return $queued;
    }

    /**
     * @return Builder<User>
     */
    private function candidates(string $timezone)
    {
        return User::query()
            ->excludingSharedAccounts()
            ->whereNotNull('onboarded_at')
            ->when($this->option('user') !== null, fn ($query) => $query->whereKey($this->option('user')))
            ->when($this->option('user') === null, fn ($query) => $query->where(function ($scope) use ($timezone): void {
                $scope->where('timezone', $timezone);

                if ($timezone === $this->fallbackTimezone()) {
                    $scope->orWhereNull('timezone');
                }
            }))
            ->whereDoesntHave('mailLogs', fn ($query) => $query
                ->where('email_type', DripEmailType::MonthlySummary)
                ->where('email_identifier', 'like', $this->month()->format('Y-m').':%'));
    }

    /**
     * @return bool whether anything was queued for this user
     */
    private function process(User $user, string $timezone): bool
    {
        if (! Feature::for($user)->active(MonthlySummaries::class) || ! $user->wantsMonthlySummaryEmail()) {
            return false;
        }

        $month = $this->month();

        if (! $this->readiness->hasDataFor($user, $month)) {
            // Nothing dated inside the month: there is no report to write, no
            // matter how many days are left in the window.
            return $this->remind($user, $month, $timezone);
        }

        if ($this->readiness->isReady($user, $month)) {
            return $this->send($user, $month, complete: true);
        }

        if ($this->isLastDay($timezone)) {
            return $this->send($user, $month, complete: false);
        }

        return $this->remind($user, $month, $timezone);
    }

    private function send(User $user, Carbon $month, bool $complete): bool
    {
        $summary = $this->summaries->freeze($user, $month, $complete);

        if ($summary === null) {
            return false;
        }

        if ($this->option('dry-run')) {
            $this->line("would send {$user->email} — {$summary->period} ({$summary->card->value})".($complete ? '' : ', incomplete'));

            return true;
        }

        SendMonthlySummaryEmailJob::dispatch($user, $summary);

        return true;
    }

    /**
     * The nudge only goes out on the first day of the window, and only to
     * someone who was already using the app. Every later day is a silent retry.
     */
    private function remind(User $user, Carbon $month, string $timezone): bool
    {
        if (! $this->isFirstDay($timezone) || ! $this->readiness->deservesReminder($user, $month)) {
            return false;
        }

        $deadline = $this->deadline($month);

        if ($this->option('dry-run')) {
            $this->line("would remind {$user->email} — {$month->format('Y-m')}");

            return true;
        }

        SendMonthlySummaryReminderEmailJob::dispatch($user, $month->format('Y-m'), $deadline->toDateString());

        return true;
    }

    private function deadline(Carbon $month): Carbon
    {
        return $month->copy()->addMonth()->startOfMonth()->addDays((int) config('monthly_summary.last_day') - 1);
    }

    private function isFirstDay(string $timezone): bool
    {
        return $this->option('user') !== null
            || now()->setTimezone($timezone)->day === (int) config('monthly_summary.first_day');
    }

    private function isLastDay(string $timezone): bool
    {
        return $this->option('user') === null
            && now()->setTimezone($timezone)->day === (int) config('monthly_summary.last_day');
    }

    private function month(): Carbon
    {
        $month = $this->option('month');

        return $month === null
            ? now()->subMonth()->startOfMonth()
            : Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
    }

    private function fallbackTimezone(): string
    {
        return (string) config('monthly_summary.fallback_timezone');
    }
}
