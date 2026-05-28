<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('budgets:generate-periods')->daily();
Schedule::command('banking:sync')->everySixHours();
Schedule::command('banks:check-logos')->weekly();
Schedule::command('banking:cancel-free-enablebanking')->lastDayOfMonth('18:00');
Schedule::command('real-estate:apply-revaluation')->monthlyOn(1, '00:00');
Schedule::command('loans:generate-balances')->monthlyOn(1, '00:00');
Schedule::command('resend:sync-leads')->dailyAt('03:00');
Schedule::command('leads:send-invitations --force --limit=150')->dailyAt('09:00');
Schedule::command('leads:send-re-invitations --force')->dailyAt('09:00');
