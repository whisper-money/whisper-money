<?php

use App\Models\ApiRequest;
use App\Models\Import;
use Illuminate\Support\Facades\Schedule;

// Hourly, because a period ends at the owner's midnight, not the server's:
// for a user in Buenos Aires that is 03:00 UTC, for one in Tokyo 15:00 UTC the
// day before. A daily run at 00:00 UTC rolled periods over hours late (or a
// day late) for most users. Every run is idempotent.
Schedule::command('budgets:generate-periods')->hourly()->withoutOverlapping(60);
// Hourly, because a month is over at each owner's midnight (03:00 UTC for
// Buenos Aires, 15:00 UTC the day before for Tokyo) and the month-end reminder
// waits for 07:00 on the owner's clock: hourly is the coarsest schedule that
// closes every timezone's month, and reminds it, within the hour (pages show
// the new month themselves if they get there first). Every run is idempotent:
// a month opens once on its (goal, month) key, and closing, the bell notice and
// the reminder are each claimed on the period, so each goes out once a month.
Schedule::command('savings-goals:generate-periods')->hourly()->withoutOverlapping(60);
// The press account keeps itself alive: every reset re-derives its 12 months of
// transactions and balances from today, so a journalist who logs in months after
// the press round still lands on current data. It also provisions the account on
// its first run, so no manual step is needed after a deploy.
Schedule::command('demo:reset --press')->dailyAt('04:00')->timezone('Europe/Madrid');
// The app-store reviewer account needs the same refresh: a review can land weeks
// after the submission, and its test cases ask about "last month" and "this
// month". Seeded once and left alone, the account had no data for either.
Schedule::command('demo:reset --review')->dailyAt('04:10')->timezone('Europe/Madrid')
    ->when(fn (): bool => filled(config('app.review.password')));
// After the demo reset at 04:00, so a freshly seeded demo account has its
// medals by the time anybody opens it. Everyone else is swept in the same pass:
// a milestone is about a closed month or a month-end balance, so once a day is
// as often as the answer can change.
Schedule::command('achievements:sweep')->dailyAt('04:30')->timezone('Europe/Madrid');
Schedule::command('banking:sync')->everySixHours();
Schedule::command('banks:check-logos')->weekly();
Schedule::command('banking:cancel-free-enablebanking')->lastDayOfMonth('18:00');
Schedule::command('real-estate:apply-revaluation')->monthlyOn(1, '00:00');
Schedule::command('loans:generate-balances')->monthlyOn(1, '00:00');
Schedule::command('email:paywall-follow-up')->dailyAt('10:00')->timezone('Europe/Madrid');
Schedule::command('email:ai-consent-follow-up')->dailyAt('10:15')->timezone('Europe/Madrid');
Schedule::command('email:inactive-no-bank')->dailyAt('09:45')->timezone('Europe/Madrid');
Schedule::command('email:inactive-banked')->dailyAt('09:47')->timezone('Europe/Madrid');
Schedule::command('email:connection-expiring')->dailyAt('09:50')->timezone('Europe/Madrid');
Schedule::command('email:user-emails-report')->monthlyOn(1, '09:05')->timezone('Europe/Madrid');
Schedule::command('banking:health --email')->dailyAt('09:30')->timezone('Europe/Madrid');
Schedule::command('stats:daily-report')->dailyAt('09:00')->timezone('Europe/Madrid');
Schedule::command('stats:ai-cohort-report')->monthlyOn(1, '09:00')->timezone('Europe/Madrid');
// Hourly through the window, not once on a fixed morning: over a thousand
// onboarded users are in American timezones, where a Madrid 9am lands in the
// middle of their night. Each pass sends to whoever it is 9am for, and the
// command is a no-op outside the 3rd-to-10th window.
Schedule::command('email:monthly-summary')->hourly();
// A full import that never got started keeps the rows the browser staged for
// it; a day on, nobody is coming back to finish that upload.
Schedule::command('model:prune', ['--model' => [Import::class]])->dailyAt('04:45')->timezone('Europe/Madrid');
// `/api/v1` usage rows past the 90 days `stats:api-usage` can usefully look
// back over (see ApiRequest::prunable()).
Schedule::command('model:prune', ['--model' => [ApiRequest::class]])->dailyAt('04:50')->timezone('Europe/Madrid');
// Black Friday 2026: the launch email on Friday 27 November and the last-day
// email on Tuesday 1 December, both at 09:00 Madrid, to free users who kept
// "Product news and offers" on. Scheduled rather than left to someone's memory,
// and pinned to 2026 because a day-and-month cron fires every year. No
// `--force`: it would suffix the identifier and defeat the per-user dedupe,
// so `--no-interaction` answers the confirmation instead. The audience is
// recomputed at each run, so whoever subscribes after the first email is out
// of the second. Copy and the matching manual command live in each view.
foreach ([
    'black-friday-2026' => ['0 9 27 11 *', '40% off Whisper Money until 1 December'],
    'black-friday-last-day-2026' => ['0 9 1 12 *', 'Last day: 40% off ends tonight'],
] as $view => [$cron, $subject]) {
    Schedule::command('email:update', [$view, '--audience' => 'unsubscribed', '--subject' => $subject, '--exclude-demo', '--no-interaction'])
        ->cron($cron)
        ->timezone('Europe/Madrid')
        ->when(fn (): bool => now('Europe/Madrid')->year === 2026);
}
