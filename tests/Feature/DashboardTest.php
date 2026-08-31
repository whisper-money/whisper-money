<?php

use App\Enums\CategoryType;
use App\Enums\LabelSource;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\Label;
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

function labelledTransaction(User $user, Label $label, array $attributes = []): Transaction
{
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -1000,
        'transaction_date' => now(),
        ...$attributes,
    ]);

    $transaction->labels()->attach($label->id);

    return $transaction;
}

function topLabels(User $user): array
{
    return test()->actingAs($user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'topLabels',
    ])->assertOk()->json('props.topLabels');
}

test('dashboard top labels add up the expenses carrying each label', function () {
    $user = User::factory()->onboarded()->create();
    $trip = Label::factory()->create(['user_id' => $user->id, 'name' => 'Trip']);
    $gifts = Label::factory()->create(['user_id' => $user->id, 'name' => 'Gifts']);
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);

    labelledTransaction($user, $trip, ['amount' => -1000, 'category_id' => $food->id]);
    labelledTransaction($user, $trip, ['amount' => -2000]);
    labelledTransaction($user, $gifts, ['amount' => -500, 'category_id' => $food->id]);

    expect(topLabels($user))->toBe([
        ['id' => $trip->id, 'name' => 'Trip', 'color' => $trip->color, 'amount' => 3000, 'previous_amount' => 0, 'total_amount' => 3500],
        ['id' => $gifts->id, 'name' => 'Gifts', 'color' => $gifts->color, 'amount' => 500, 'previous_amount' => 0, 'total_amount' => 3500],
    ]);
});

test('dashboard top labels compare against the preceding period', function () {
    $user = User::factory()->onboarded()->create();
    $trip = Label::factory()->create(['user_id' => $user->id]);

    labelledTransaction($user, $trip, ['amount' => -1000]);
    labelledTransaction($user, $trip, ['amount' => -400, 'transaction_date' => now()->subDays(45)]);

    $labels = topLabels($user);

    expect($labels)->toHaveCount(1)
        ->and($labels[0]['amount'])->toBe(1000)
        ->and($labels[0]['previous_amount'])->toBe(400);
});

test('dashboard top labels leave out savings goal labels and money coming in', function () {
    $user = User::factory()->onboarded()->create();
    $goalLabel = Label::factory()->create(['user_id' => $user->id, 'source' => LabelSource::SavingsGoal]);
    $salary = Label::factory()->create(['user_id' => $user->id]);
    $income = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income]);

    labelledTransaction($user, $goalLabel, ['amount' => -1000]);
    labelledTransaction($user, $salary, ['amount' => 5000, 'category_id' => $income->id]);

    expect(topLabels($user))->toBe([]);
});

test('dashboard top labels are empty when nothing is labelled', function () {
    $user = User::factory()->onboarded()->create();

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -1000,
        'transaction_date' => now(),
    ]);

    expect(topLabels($user))->toBe([]);
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
