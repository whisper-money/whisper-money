<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('demo:reset')->twiceDaily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('budgets:generate-periods')->daily();
Schedule::command('banking:sync')->everySixHours();
Schedule::command('banks:check-logos')->weekly();
Schedule::command('real-estate:apply-revaluation')->monthlyOn(1, '00:00');
