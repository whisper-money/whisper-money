<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('demo:reset')->twiceDaily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('budgets:generate-periods')->daily();
