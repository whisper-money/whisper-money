<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('budgets:generate-periods')->daily();
// The press account keeps itself alive: every reset re-derives its 12 months of
// transactions and balances from today, so a journalist who logs in months after
// the press round still lands on current data. It also provisions the account on
// its first run, so no manual step is needed after a deploy.
Schedule::command('demo:reset --press')->dailyAt('04:00')->timezone('Europe/Madrid');
Schedule::command('banking:sync')->everySixHours();
Schedule::command('banks:check-logos')->weekly();
// Connectors move in and out of beta at the provider, so the flag stored on
// each connection goes stale on its own. Weekly is plenty: it is 17 catalogue
// calls and a badge, not something a user is waiting on.
Schedule::command('banking:sync-aspsp-beta')->weekly();
Schedule::command('banking:cancel-free-enablebanking')->lastDayOfMonth('18:00');
Schedule::command('real-estate:apply-revaluation')->monthlyOn(1, '00:00');
Schedule::command('loans:generate-balances')->monthlyOn(1, '00:00');
Schedule::command('email:paywall-follow-up')->dailyAt('10:00')->timezone('Europe/Madrid');
Schedule::command('email:ai-consent-follow-up')->dailyAt('10:15')->timezone('Europe/Madrid');
Schedule::command('email:inactive-no-bank')->dailyAt('09:45')->timezone('Europe/Madrid');
Schedule::command('email:user-emails-report')->monthlyOn(1, '09:05')->timezone('Europe/Madrid');
Schedule::command('banking:health --email')->dailyAt('09:30')->timezone('Europe/Madrid');
Schedule::command('stats:daily-report')->dailyAt('09:00')->timezone('Europe/Madrid');
Schedule::command('stats:ai-cohort-report')->monthlyOn(1, '09:00')->timezone('Europe/Madrid');
Schedule::command('stats:subscription-funnel')->weekly()->mondays()->at('09:15')->timezone('Europe/Madrid');
// Hourly through the window, not once on a fixed morning: over a thousand
// onboarded users are in American timezones, where a Madrid 9am lands in the
// middle of their night. Each pass sends to whoever it is 9am for, and the
// command is a no-op outside the 3rd-to-10th window.
Schedule::command('email:monthly-summary')->hourly();
