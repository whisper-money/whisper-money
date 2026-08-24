<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

test('new guests are redirected to the registration page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('register'));
});

test('returning guests are redirected to the login page', function () {
    $this
        ->withCookie('whisper_money_returning_user', '1')
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('new guests are redirected to the login page when registration is disabled', function () {
    config(['auth.registration_enabled' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('dashboard'))->assertOk();
});

test('dashboard top categories roll child spending up into the parent', function () {
    $user = User::factory()->onboarded()->create();
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->childOf($food)->create(['user_id' => $user->id, 'name' => 'Groceries']);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => -1000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $groceries->id,
        'amount' => -2000,
        'transaction_date' => now(),
    ]);

    $response = $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'topCategories',
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'props.topCategories')
        ->assertJsonPath('props.topCategories.0.category.id', $food->id)
        ->assertJsonPath('props.topCategories.0.amount', 3000);
});

test('an archived account keeps its history but stops counting from the day it was archived', function () {
    fakeCurrencyApi();

    $user = User::factory()->onboarded()->create();
    $archivedOn = now()->subMonthsNoOverflow(3)->startOfMonth();

    $account = Account::factory()->create([
        'user_id' => $user->id,
        'archived_at' => $archivedOn,
    ]);

    // One balance a year back is enough: a balance carries forward, so every
    // month from then on reads 5000 unless something else zeroes it.
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonthsNoOverflow(12)->startOfMonth(),
        'balance' => 5000,
    ]);

    $evolution = $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'netWorthEvolution',
    ])->assertOk()->json('props.netWorthEvolution');

    $points = collect($evolution['data'])->keyBy('month');

    // Still on the chart, so the months it was open read as they always did...
    expect($evolution['accounts'])->toHaveKey($account->id);
    expect($points[$archivedOn->copy()->subMonthNoOverflow()->format('Y-m')][$account->id])->toBe(5000);

    // ...and worth nothing from the month it was archived onwards.
    expect($points[$archivedOn->format('Y-m')][$account->id])->toBe(0);
    expect($points[now()->format('Y-m')][$account->id])->toBe(0);
});

test('an account hidden on the dashboard still counts towards net worth', function () {
    fakeCurrencyApi();

    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'hidden_on_dashboard' => true,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonthsNoOverflow(12)->startOfMonth(),
        'balance' => 5000,
    ]);

    $evolution = $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'netWorthEvolution',
    ])->assertOk()->json('props.netWorthEvolution');

    expect($evolution['accounts'][$account->id]['hidden_on_dashboard'])->toBeTrue();
    expect(collect($evolution['data'])->keyBy('month')[now()->format('Y-m')][$account->id])->toBe(5000);
});
