<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

test('scheduled commands do not include horizon snapshot', function () {
    Artisan::call('schedule:list');

    $commands = collect(Schedule::events())
        ->map(fn (Event $event): string => $event->command)
        ->filter();

    expect($commands)->each->not->toContain('horizon:snapshot');
    expect($commands->implode("\n"))->not->toContain('horizon:snapshot');
});
