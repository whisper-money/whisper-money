<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Dashboards\FirstDashboardState;
use Illuminate\Support\Facades\Http;

/*
 * What the system dashboard is told when it holds no real movement yet: only
 * the onboarding estimates, or only balances with no current account. Every
 * other user gets null, and the dashboard as it always was.
 */

beforeEach(function (): void {
    Http::fake();
    $this->travelTo('2026-11-24 12:00:00');
    $this->withoutVite();
});

function stateUser(array $attributes = []): User
{
    return User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
        'onboarded_at' => now(),
        ...$attributes,
    ]);
}

function stateAccount(User $user, string $type = 'checking', array $attributes = []): Account
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'currency_code' => 'EUR',
        'type' => $type,
        ...$attributes,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 800000,
    ]);

    return $account;
}

/**
 * One round figure typed in during the onboarding, on the first of last month.
 */
function stateEstimate(User $user, Account $account, int $amount, ?Category $category, string $description = 'Rent'): Transaction
{
    $label = Label::query()->where('user_id', $user->id)->where('source', 'estimate')->first()
        ?? Label::factory()->estimate()->create(['user_id' => $user->id]);

    $estimate = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'currency_code' => 'EUR',
        'description' => $description,
        'amount' => -$amount,
        'transaction_date' => '2026-10-01',
    ]);
    $estimate->labels()->attach($label->id);

    return $estimate;
}

function stateCategory(User $user, string $name): Category
{
    return Category::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'type' => CategoryType::Expense,
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function dashboardPropFor(User $user, string $prop): ?array
{
    return test()->actingAs($user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => $prop,
    ])->assertOk()->json("props.{$prop}");
}

it('tells an estimate-only dashboard the month typed in, its rows and the guess', function (): void {
    $user = stateUser(['onboarding_answers' => ['spending_guess' => 120000]]);
    $account = stateAccount($user);

    stateEstimate($user, $account, 110000, stateCategory($user, 'Rent'));
    stateEstimate($user, $account, 40000, stateCategory($user, 'Groceries'));
    stateEstimate($user, $account, 4500, null, 'Subscriptions');

    $state = dashboardPropFor($user, 'firstDashboardState');

    expect($state)->toMatchArray([
        'state' => 'estimates',
        'tracking_since' => '2026-11-24',
        'accounts_count' => 1,
        'spending_guess' => 120000,
        'currency_code' => 'EUR',
    ])->and($state['estimates'])->toMatchArray([
        'month' => '2026-10',
        'count' => 3,
        'total' => 154500,
        'currency_code' => 'EUR',
        'account_id' => $account->id,
        'rows' => [
            ['name' => 'Rent', 'amount' => 110000],
            ['name' => 'Groceries', 'amount' => 40000],
            ['name' => 'Subscriptions', 'amount' => 4500],
        ],
    ]);
});

it('keeps the "Finish setting up" card away while the estimates card asks for the import', function (): void {
    $user = stateUser();
    $account = stateAccount($user);
    stateAccount($user, 'loan', ['name' => 'Mortgage'])->balances()->delete();
    stateEstimate($user, $account, 110000, null);

    expect(dashboardPropFor($user, 'setupChecklist'))->toBeNull();
});

it('offers no emailed link when the estimates sit in an account that cannot take a file', function (): void {
    $user = stateUser();
    $account = stateAccount($user);
    stateEstimate($user, $account, 110000, null);
    $account->update(['archived_at' => now()]);

    expect(dashboardPropFor($user, 'firstDashboardState')['estimates']['account_id'])->toBeNull();
});

it('tells a balance-only dashboard how many accounts it holds', function (): void {
    $user = stateUser(['onboarding_answers' => ['spending_guess' => 120000]]);
    stateAccount($user, 'investment');
    stateAccount($user, 'retirement');

    expect(dashboardPropFor($user, 'firstDashboardState'))->toBe([
        'state' => 'balances',
        'tracking_since' => '2026-11-24',
        'accounts_count' => 2,
        'spending_guess' => 120000,
        'currency_code' => 'EUR',
    ]);
});

it('reads the tracking day on the user\'s own calendar', function (): void {
    $this->travelTo('2026-11-24 02:00:00');
    $user = stateUser(['timezone' => 'America/New_York']);
    stateAccount($user, 'investment');

    expect(dashboardPropFor($user, 'firstDashboardState')['tracking_since'])->toBe('2026-11-23');
});

it('leaves the dashboard as it is for a user with real movements', function (): void {
    $user = stateUser();
    $account = stateAccount($user);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-11-10',
    ]);

    expect(dashboardPropFor($user, 'firstDashboardState'))->toBeNull();
});

it('leaves the dashboard as it is when real movements sit beside the estimates', function (): void {
    $user = stateUser();
    $account = stateAccount($user);
    stateEstimate($user, $account, 110000, null);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-11-10',
    ]);

    expect(dashboardPropFor($user, 'firstDashboardState'))->toBeNull()
        ->and(dashboardPropFor($user, 'setupChecklist'))->not->toBeNull();
});

it('leaves an empty current account to the dashboard as it is', function (): void {
    $user = stateUser();
    stateAccount($user, 'checking');
    stateAccount($user, 'investment');

    expect(dashboardPropFor($user, 'firstDashboardState'))->toBeNull();
});

it('has no state for a user without accounts or still in the onboarding', function (): void {
    expect(dashboardPropFor(stateUser(), 'firstDashboardState'))->toBeNull();

    $onboarding = stateUser(['onboarded_at' => null]);
    stateAccount($onboarding, 'investment');

    expect(app(FirstDashboardState::class)->for($onboarding))->toBeNull();
});
