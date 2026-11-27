<?php

use App\Models\User;
use App\Models\UserActiveDay;
use App\Services\Admin\SignupChart;
use App\Support\PeriodSelection;

beforeEach(function () {
    $this->travelTo('2026-11-27 12:00:00');
});

/**
 * @return array<string, mixed>
 */
function signupChart(string $type = 'month', ?string $period = null): array
{
    return app(SignupChart::class)->for(new PeriodSelection($type, $period));
}

/**
 * @return array<string, array<string, mixed>>
 */
function chartPointsByStart(string $type = 'month', ?string $period = null): array
{
    return collect(signupChart($type, $period)['points'])->keyBy('start')->all();
}

test('with no period it opens on the month in progress, by day, next to the month before', function () {
    $chart = signupChart();

    expect($chart)
        ->period_type->toBe('month')
        ->period->toBe('2026-11')
        ->previous_period->toBe('2026-10')
        ->bucket->toBe('day')
        ->and($chart['points'])->toHaveCount(30)
        ->and($chart['points'][0])->toBe([
            'start' => '2026-11-01',
            'end' => '2026-11-01',
            'signups' => 0,
            'active_users' => 0,
            'previous_start' => '2026-10-01',
            'previous_end' => '2026-10-01',
            'previous_signups' => 0,
            'previous_active_users' => 0,
        ]);
});

test('the days after today are on the axis with nothing drawn', function () {
    $points = chartPointsByStart();

    expect($points['2026-11-27']['signups'])->toBe(0)
        ->and($points['2026-11-28']['signups'])->toBeNull()
        ->and($points['2026-11-28']['active_users'])->toBeNull()
        // The month before is complete, so its side is always drawn.
        ->and($points['2026-11-28']['previous_signups'])->toBe(0)
        ->and($points['2026-11-30']['previous_start'])->toBe('2026-10-30');
});

test('a shorter previous period leaves the last buckets without a previous side', function () {
    // March has 31 days, February 28.
    $points = signupChart('month', '2026-03')['points'];

    expect($points)->toHaveCount(31)
        ->and($points[27]['previous_start'])->toBe('2026-02-28')
        ->and($points[28]['previous_start'])->toBeNull()
        ->and($points[28]['previous_signups'])->toBeNull();
});

test('a longer previous period is cut to the selected one', function () {
    // November has 30 days, October 31.
    User::factory()->create(['created_at' => '2026-10-31 10:00:00']);

    $chart = signupChart('month', '2026-11');

    expect($chart['points'])->toHaveCount(30)
        ->and(end($chart['points'])['previous_start'])->toBe('2026-10-30');
});

test('sign-ups are counted on the UTC day they happened', function () {
    User::factory()->count(3)->create(['created_at' => '2026-11-25 23:59:00']);
    User::factory()->create(['created_at' => '2026-11-26 00:00:00']);
    User::factory()->count(2)->create(['created_at' => '2026-10-05 12:00:00']);
    // Before both periods.
    User::factory()->create(['created_at' => '2026-09-30 12:00:00']);

    $points = chartPointsByStart();

    expect($points['2026-11-25']['signups'])->toBe(3)
        ->and($points['2026-11-26']['signups'])->toBe(1)
        ->and($points['2026-11-05']['previous_signups'])->toBe(2)
        ->and(collect($points)->sum('signups'))->toBe(4)
        ->and(collect($points)->sum('previous_signups'))->toBe(2);
});

test('a bucket counts each user active in the 30 days up to it once', function () {
    $regular = User::factory()->create(['created_at' => '2026-01-01']);
    $lapsed = User::factory()->create(['created_at' => '2026-01-01']);

    foreach (['2026-10-01', '2026-11-01', '2026-11-02', '2026-11-20'] as $date) {
        UserActiveDay::query()->create(['user_id' => $regular->id, 'date' => $date]);
    }
    UserActiveDay::query()->create(['user_id' => $lapsed->id, 'date' => '2026-10-15']);

    $points = chartPointsByStart();

    // 2026-11-01's window opens on 2026-10-03: both users are in it.
    expect($points['2026-11-01']['active_users'])->toBe(2)
        // 2026-11-13's window opens on 2026-10-15, the lapsed user's last day.
        ->and($points['2026-11-13']['active_users'])->toBe(2)
        ->and($points['2026-11-14']['active_users'])->toBe(1)
        ->and($points['2026-11-27']['active_users'])->toBe(1)
        // 2026-10-01 is the previous side of 2026-11-01.
        ->and($points['2026-11-01']['previous_active_users'])->toBe(1)
        ->and($points['2026-11-15']['previous_active_users'])->toBe(2);
});

test('a quarter is drawn by day, next to the quarter before', function () {
    $chart = signupChart('quarter', '2026-Q1');

    expect($chart)
        ->period->toBe('2026-Q1')
        ->previous_period->toBe('2025-Q4')
        ->bucket->toBe('day')
        ->and($chart['points'])->toHaveCount(90)
        ->and($chart['points'][0]['previous_start'])->toBe('2025-10-01')
        ->and(end($chart['points'])['end'])->toBe('2026-03-31');
});

test('a year is drawn by ISO week, clipped to the year', function () {
    User::factory()->count(2)->create(['created_at' => '2026-01-03 12:00:00']);
    User::factory()->create(['created_at' => '2026-01-05 12:00:00']);
    $active = User::factory()->create(['created_at' => '2025-01-01']);
    UserActiveDay::query()->create(['user_id' => $active->id, 'date' => '2026-11-26']);

    $chart = signupChart('year', '2026');
    $points = $chart['points'];

    expect($chart)
        ->period->toBe('2026')
        ->previous_period->toBe('2025')
        ->bucket->toBe('week')
        // 2026-01-01 is a Thursday: the first week is Thu to Sun.
        ->and($points[0])->toMatchArray(['start' => '2026-01-01', 'end' => '2026-01-04', 'signups' => 2])
        ->and($points[1])->toMatchArray(['start' => '2026-01-05', 'end' => '2026-01-11', 'signups' => 1])
        // 2025-01-01 is a Wednesday.
        ->and($points[0])->toMatchArray(['previous_start' => '2025-01-01', 'previous_end' => '2025-01-05'])
        ->and(end($points)['end'])->toBe('2026-12-31');

    $thisWeek = collect($points)->firstWhere('start', '2026-11-23');

    // The week in progress counts active users up to today, not its Sunday.
    expect($thisWeek['active_users'])->toBe(1)
        ->and(collect($points)->firstWhere('start', '2026-11-30')['active_users'])->toBeNull();
});

test('an in-progress period is compared with the same stretch of the one before', function () {
    $active = User::factory()->create(['created_at' => '2025-01-01']);
    UserActiveDay::query()->create(['user_id' => $active->id, 'date' => '2026-10-20']);
    User::factory()->count(3)->create(['created_at' => '2026-11-10 12:00:00']);
    User::factory()->count(2)->create(['created_at' => '2026-10-10 12:00:00']);
    // After the 27th: outside the stretch being compared.
    User::factory()->count(5)->create(['created_at' => '2026-10-29 12:00:00']);

    expect(signupChart()['summary'])->toBe([
        'signups' => 3,
        'active_users' => 0,
        'previous_signups' => 2,
        'previous_active_users' => 1,
    ]);
});

test('a finished period is compared with the whole one before', function () {
    User::factory()->count(5)->create(['created_at' => '2026-09-30 12:00:00']);
    User::factory()->count(4)->create(['created_at' => '2026-10-31 12:00:00']);

    expect(signupChart('month', '2026-10')['summary'])->toMatchArray([
        'signups' => 4,
        'previous_signups' => 5,
    ]);
});

test('a period that has not started draws nothing and runs no activity query', function () {
    $chart = signupChart('month', '2027-02');

    expect(collect($chart['points'])->pluck('signups')->filter()->all())->toBe([])
        ->and($chart['summary'])->toBe(['signups' => 0, 'active_users' => 0, 'previous_signups' => 0, 'previous_active_users' => 0]);
});
