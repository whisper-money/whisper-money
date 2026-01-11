<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('demo:reset')->twiceDaily();
