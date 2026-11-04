<?php

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

/*
 * The server clock is UTC. 2026-11-01 00:30 UTC is still 31 October in Buenos
 * Aires (UTC-3) and 2026-10-31 16:00 UTC is already 1 November in Tokyo
 * (UTC+9): the dashboard cards pick "this month" and "the last 30 days" on the
 * owner's own date.
 */

beforeEach(function () {
    $this->withoutVite();
    fakeCurrencyApi();
});

afterEach(function () {
    Carbon::setTestNow();
});

dataset('dashboard owner clocks', [
    'Buenos Aires at 00:30 UTC on 1 November' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', '2026-10-31'],
    'UTC at 00:30 UTC on 1 November' => ['UTC', '2026-11-01 00:30:00', '2026-11-01'],
    'Tokyo at 16:00 UTC on 31 October' => ['Asia/Tokyo', '2026-10-31 16:00:00', '2026-11-01'],
    'no timezone at 00:30 UTC on 1 November' => [null, '2026-11-01 00:30:00', '2026-11-01'],
]);

function dashboardOwnerAt(?string $timezone, string $now): User
{
    Carbon::setTestNow(Carbon::parse($now));

    return User::factory()->onboarded()->create(['timezone' => $timezone]);
}

function dashboardCard(User $user, string $prop): array
{
    return test()->actingAs($user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => $prop,
    ])->assertOk()->json("props.{$prop}");
}

function dashboardExpense(User $user, string $date, int $amount, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense])->id,
        'amount' => -$amount,
        'transaction_date' => $date,
        ...$attributes,
    ]);
}

it('compares the owner\'s current month with the one before it on the cashflow card', function (?string $timezone, string $now, string $today) {
    $user = dashboardOwnerAt($timezone, $now);
    dashboardExpense($user, '2026-09-20', 2000);
    dashboardExpense($user, '2026-10-20', 1000);

    $summary = dashboardCard($user, 'cashflowSummary');

    [$current, $previous] = $today === '2026-10-31' ? [1000, 2000] : [0, 1000];
    expect($summary['current']['expense'])->toBe($current)
        ->and($summary['previous']['expense'])->toBe($previous);
})->with('dashboard owner clocks');

it('ranks the 30 days ending on the owner\'s today on the top categories card', function (?string $timezone, string $now, string $today) {
    $user = dashboardOwnerAt($timezone, $now);
    $firstDay = Carbon::parse($today)->subDays(29)->toDateString();
    $inside = dashboardExpense($user, $today, 1000);
    $oldest = dashboardExpense($user, $firstDay, 2000);
    dashboardExpense($user, Carbon::parse($firstDay)->subDay()->toDateString(), 3000);
    dashboardExpense($user, Carbon::parse($today)->addDay()->toDateString(), 4000);

    $categories = collect(dashboardCard($user, 'topCategories'));

    expect($categories->pluck('category_id')->all())->toBe([$oldest->category_id, $inside->category_id])
        ->and($categories->first()['previous_amount'])->toBe(0);
})->with('dashboard owner clocks');

it('ranks the 30 days ending on the owner\'s today on the top labels card', function (?string $timezone, string $now, string $today) {
    $user = dashboardOwnerAt($timezone, $now);
    $current = Label::factory()->create(['user_id' => $user->id]);
    $stale = Label::factory()->create(['user_id' => $user->id]);
    dashboardExpense($user, $today, 1000)->labels()->attach($current->id);
    dashboardExpense($user, Carbon::parse($today)->subDays(30)->toDateString(), 2000)->labels()->attach($stale->id);

    $labels = collect(dashboardCard($user, 'topLabels'));

    expect($labels->pluck('id')->all())->toBe([$current->id]);
})->with('dashboard owner clocks');
