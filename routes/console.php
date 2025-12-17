<?php

use Illuminate\Console\Scheduling\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();
