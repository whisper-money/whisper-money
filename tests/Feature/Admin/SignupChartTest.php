<?php

use App\Models\User;
use App\Models\UserActiveDay;
use App\Services\Admin\SignupChart;

beforeEach(function () {
    $this->travelTo('2026-11-27 12:00:00');
});

/**
 * @return array<string, array{date: string, signups: int, active_users: int}>
 */
function chartByDate(): array
{
    return collect(app(SignupChart::class)->points())->keyBy('date')->all();
}

test('every one of the last 30 days is there, oldest first, empty days included', function () {
    $points = app(SignupChart::class)->points();

    expect($points)->toHaveCount(30)
        ->and($points[0])->toBe(['date' => '2026-10-29', 'signups' => 0, 'active_users' => 0])
        ->and($points[29]['date'])->toBe('2026-11-27');
});

test('sign-ups are counted on the UTC day they happened', function () {
    User::factory()->count(3)->create(['created_at' => '2026-11-25 23:59:00']);
    User::factory()->create(['created_at' => '2026-11-26 00:00:00']);
    // Before the chart's range.
    User::factory()->create(['created_at' => '2026-10-28 12:00:00']);

    $points = chartByDate();

    expect($points['2026-11-25']['signups'])->toBe(3)
        ->and($points['2026-11-26']['signups'])->toBe(1)
        ->and(collect($points)->sum('signups'))->toBe(4);
});

test('a day counts each user active in the 30 days up to it once', function () {
    $regular = User::factory()->create();
    $lapsed = User::factory()->create();

    foreach (['2026-10-01', '2026-11-01', '2026-11-02', '2026-11-20'] as $date) {
        UserActiveDay::query()->create(['user_id' => $regular->id, 'date' => $date]);
    }
    UserActiveDay::query()->create(['user_id' => $lapsed->id, 'date' => '2026-10-15']);

    $points = chartByDate();

    // 2026-10-29's window opens on 2026-09-30: both users are in it.
    expect($points['2026-10-29']['active_users'])->toBe(2)
        // 2026-11-13's window opens on 2026-10-15, the lapsed user's last day.
        ->and($points['2026-11-13']['active_users'])->toBe(2)
        ->and($points['2026-11-14']['active_users'])->toBe(1)
        ->and($points['2026-11-27']['active_users'])->toBe(1);
});
