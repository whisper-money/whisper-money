<?php

use App\Enums\BudgetPeriodType;
use App\Enums\CategoryType;
use App\Enums\RolloverType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Support\Facades\Http;

/*
 * The "Finish setting up" card on the system dashboard: the steps that apply to
 * what the user brought in, each done or still to do, offered in the first days
 * after onboarding and gone once every step is done or the user hides it.
 */

beforeEach(function (): void {
    Http::fake();
    $this->travelTo('2026-11-24 12:00:00');
    $this->withoutVite();
});

function setupUser(array $attributes = []): User
{
    return User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
        ...$attributes,
    ]);
}

function setupAccount(User $user, array $attributes = []): Account
{
    return Account::factory()->create([
        'user_id' => $user->id,
        'currency_code' => 'EUR',
        'type' => 'checking',
        ...$attributes,
    ]);
}

function setupBalance(Account $account): void
{
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function setupMovements(User $user, Account $account, int $count, array $attributes = []): void
{
    Transaction::factory()->count($count)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'currency_code' => 'EUR',
        'transaction_date' => '2026-11-10',
        ...$attributes,
    ]);
}

function setupCategory(User $user): Category
{
    return Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Expense,
    ]);
}

/**
 * The card as the dashboard's deferred request sends it.
 *
 * @return array<string, mixed>|null
 */
function setupChecklistFor(User $user): ?array
{
    return test()->actingAs($user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'setupChecklist',
    ])->assertOk()->json('props.setupChecklist');
}

/**
 * @return array<string, mixed>|null
 */
function setupStep(?array $checklist, string $key): ?array
{
    return collect($checklist['steps'] ?? [])->firstWhere('key', $key);
}

it('lists the imported movements, the limit, what is left to categorize and the account without a balance', function (): void {
    $user = setupUser(['onboarding_answers' => ['target' => 25000]]);
    $account = setupAccount($user, ['name' => 'BBVA nómina']);
    setupBalance($account);
    $loan = setupAccount($user, ['name' => 'Hipoteca piso', 'type' => 'loan']);

    setupMovements($user, $account, 3, ['transaction_date' => '2026-06-04']);
    setupMovements($user, $account, 2, ['transaction_date' => '2026-11-10', 'category_id' => setupCategory($user)->id]);

    app(BudgetService::class)->create($user, [
        'name' => 'Monthly spending',
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::Reset,
        'is_catch_all' => true,
    ], 168400);

    $checklist = setupChecklistFor($user);

    expect(collect($checklist['steps'])->pluck('key')->all())
        ->toBe(['imported', 'spending_limit', 'categorize', 'balance'])
        ->and(setupStep($checklist, 'imported'))->toMatchArray([
            'done' => true,
            'count' => 5,
            'first_date' => '2026-06-04',
            'last_date' => '2026-11-10',
            'accounts_count' => 1,
            'account_name' => 'BBVA nómina',
        ])
        ->and(setupStep($checklist, 'spending_limit'))->toMatchArray([
            'done' => true,
            'limit' => 168400,
            'under_last_month' => 25000,
            'currency_code' => 'EUR',
        ])
        ->and(setupStep($checklist, 'categorize'))->toMatchArray(['done' => false, 'count' => 3])
        ->and(setupStep($checklist, 'balance'))->toMatchArray(['done' => false, 'others_count' => 0])
        ->and(setupStep($checklist, 'balance')['account'])->toMatchArray([
            'id' => $loan->id,
            'name' => 'Hipoteca piso',
            'type' => 'loan',
        ]);
});

it('leaves onboarding estimates out of the movements and of what is left to categorize', function (): void {
    $user = setupUser();
    $account = setupAccount($user);
    setupBalance($account);
    setupMovements($user, $account, 2);

    $estimate = Label::factory()->estimate()->create(['user_id' => $user->id]);
    $guess = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'transaction_date' => '2026-11-01',
    ]);
    $guess->labels()->attach($estimate->id);

    $checklist = setupChecklistFor($user);

    expect(setupStep($checklist, 'imported')['count'])->toBe(2)
        ->and(setupStep($checklist, 'categorize')['count'])->toBe(2);
});

it('names no account when the movements came into several', function (): void {
    $user = setupUser();
    $first = setupAccount($user);
    $second = setupAccount($user);
    setupBalance($first);
    setupBalance($second);
    setupMovements($user, $first, 1);
    setupMovements($user, $second, 1);

    expect(setupStep(setupChecklistFor($user), 'imported'))
        ->toMatchArray(['accounts_count' => 2, 'account_name' => null]);
});

it('offers a spending limit as a step still to do when none was set', function (): void {
    $user = setupUser();
    $account = setupAccount($user);
    setupBalance($account);
    setupMovements($user, $account, 1);

    expect(setupStep(setupChecklistFor($user), 'spending_limit'))
        ->toMatchArray(['done' => false, 'limit' => null, 'under_last_month' => null]);
});

it('skips the movement steps for a user without movements', function (): void {
    $user = setupUser();
    setupAccount($user, ['type' => 'loan']);

    $checklist = setupChecklistFor($user);

    expect(collect($checklist['steps'])->pluck('key')->all())->toBe(['balance']);
});

it('only asks for a balance on accounts that can have one and do not get it from a bank', function (): void {
    $user = setupUser();
    $account = setupAccount($user);
    setupBalance($account);
    setupMovements($user, $account, 1);

    setupAccount($user, ['type' => 'credit_card']);
    setupAccount($user, ['banking_connection_id' => BankingConnection::factory()->create(['user_id' => $user->id])->id]);
    setupAccount($user, ['archived_at' => now()]);

    expect(setupStep(setupChecklistFor($user), 'balance'))->toBeNull();
});

it('counts the other accounts still without a balance', function (): void {
    $user = setupUser();
    setupAccount($user, ['type' => 'loan', 'position' => 0]);
    setupAccount($user, ['type' => 'real_estate', 'position' => 1]);
    setupAccount($user, ['type' => 'savings', 'position' => 2]);

    expect(setupStep(setupChecklistFor($user), 'balance')['others_count'])->toBe(2);
});

it('draws no card once every step is done', function (): void {
    $user = setupUser();
    $account = setupAccount($user);
    setupBalance($account);
    setupMovements($user, $account, 2, ['category_id' => setupCategory($user)->id]);

    app(BudgetService::class)->create($user, [
        'name' => 'Monthly spending',
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::Reset,
        'is_catch_all' => true,
    ], 100000);

    expect(setupChecklistFor($user))->toBeNull();
});

it('draws no card once the first days after onboarding are over', function (): void {
    $user = setupUser(['onboarded_at' => now()->subDays(15)]);
    $account = setupAccount($user);
    setupMovements($user, $account, 2);

    expect(setupChecklistFor($user))->toBeNull();
});

it('hides the card for good once the user dismisses it', function (): void {
    $user = setupUser();
    $account = setupAccount($user);
    setupMovements($user, $account, 2);

    expect(setupChecklistFor($user))->not->toBeNull();

    $this->actingAs($user)
        ->post(route('dashboard.setup.dismiss'))
        ->assertNoContent();

    expect($user->fresh()->dashboard_setup_dismissed_at)->not->toBeNull()
        ->and(setupChecklistFor($user->fresh()))->toBeNull();
});

it('keeps the demo account from hiding the card for everybody', function (): void {
    config(['app.demo.email' => 'demo@whisper.money']);
    $user = setupUser(['email' => 'demo@whisper.money']);

    $this->actingAs($user)->post(route('dashboard.setup.dismiss'));

    expect($user->fresh()->dashboard_setup_dismissed_at)->toBeNull();
});
