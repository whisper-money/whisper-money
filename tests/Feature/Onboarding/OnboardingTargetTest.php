<?php

use App\Enums\BudgetPeriodType;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/** Somebody at the end of the flow, whose currency the figures come back in. */
function closingUser(): User
{
    return User::factory()->create([
        'onboarded_at' => null,
        'currency_code' => 'EUR',
    ]);
}

/**
 * A month of outgoings on one account: `$charges` as [merchant, amount] pairs,
 * amounts positive, repeated in each of the last `$months` months.
 */
function spentEachMonth(Account $account, array $charges, int $months = 1): void
{
    foreach (range(1, $months) as $back) {
        foreach ($charges as $index => [$merchant, $amount]) {
            Transaction::factory()->for($account->user)->for($account)->create([
                'category_id' => null,
                'transaction_date' => now()->startOfMonth()->subMonths($back)->addDays($index + 1),
                'amount' => -$amount,
                'currency_code' => 'EUR',
                'creditor_name' => $merchant,
            ]);
        }
    }
}

/**
 * Five merchants, because a month under `OnboardingRevealService::MIN_TRANSACTIONS`
 * is an anecdote rather than a month and never gets revealed. They add up to
 * €1,000 and, repeated, they are also what counts as recurring.
 *
 * @var list<array{string, int}>
 */
const RECURRING_CHARGES = [
    ['MERCADONA', 60000],
    ['GLOVO', 20000],
    ['AMAZON', 10000],
    ['NETFLIX', 5000],
    ['SPOTIFY', 5000],
];

/** A checking account with movements on it, which is what a target needs. */
function spendingAccount(User $user): Account
{
    return Account::factory()->for($user)->create([
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);
}

it('reports what the user has to show for the flow', function () {
    $user = closingUser();
    $account = spendingAccount($user);
    spentEachMonth($account, RECURRING_CHARGES, months: 3);
    AutomationRule::factory()->count(2)->for($user)->create();

    $this->actingAs($user)
        ->getJson('/onboarding/summary')
        ->assertOk()
        ->assertJson([
            'currency_code' => 'EUR',
            'accounts' => 1,
            'connected_accounts' => 0,
            'transactions' => 15,
            'months' => 3,
            'rules' => 2,
            'monthly_spending' => 100000,
            // Every one of them billed the same amount three months running.
            'recurring_count' => 5,
            'recurring_amount' => 100000,
            'target' => null,
        ]);
});

it('reports no spending for the user who brought no movements', function () {
    $user = closingUser();
    Account::factory()->for($user)->create(['type' => 'retirement', 'currency_code' => 'EUR']);

    $this->actingAs($user)
        ->getJson('/onboarding/summary')
        ->assertOk()
        ->assertJson([
            'accounts' => 1,
            'transactions' => 0,
            'months' => 0,
            'monthly_spending' => null,
            'target' => null,
        ]);
});

it('turns the target into the catch-all budget that holds it', function () {
    Queue::fake();

    $user = closingUser();
    spentEachMonth(spendingAccount($user), RECURRING_CHARGES);

    $this->actingAs($user)
        ->postJson('/onboarding/target', ['amount' => 20000, 'warn' => true])
        ->assertOk()
        ->assertJson(['target' => 20000]);

    $budget = $user->budgets()->sole();

    expect($budget->is_catch_all)->toBeTrue()
        ->and($budget->period_type)->toBe(BudgetPeriodType::Monthly)
        ->and($budget->notify_on_close_to_limit)->toBeTrue()
        ->and($budget->notify_on_over_limit)->toBeTrue()
        // What is left of the month once the target is set aside.
        ->and($budget->getCurrentPeriod()->allocated_amount)->toBe(80000);

    // Only a target with a budget behind it may be reported by step 11.
    expect($user->fresh()->onboarding_answers)->toMatchArray(['target' => 20000]);
});

it('leaves the warnings off when the toggle was turned off', function () {
    Queue::fake();

    $user = closingUser();
    spentEachMonth(spendingAccount($user), RECURRING_CHARGES);

    $this->actingAs($user)
        ->postJson('/onboarding/target', ['amount' => 20000, 'warn' => false])
        ->assertOk();

    $budget = $user->budgets()->sole();

    expect($budget->notify_on_close_to_limit)->toBeFalse()
        ->and($budget->notify_on_over_limit)->toBeFalse();
});

// A reload, or a second run at the step, must not leave two budgets claiming
// the same movements.
it('keeps the catch-all the user already has', function () {
    Queue::fake();

    $user = closingUser();
    spentEachMonth(spendingAccount($user), RECURRING_CHARGES);
    $existing = Budget::factory()->for($user)->create(['is_catch_all' => true]);

    $this->actingAs($user)
        ->postJson('/onboarding/target', ['amount' => 20000, 'warn' => true])
        ->assertOk()
        ->assertJson(['budget_id' => $existing->id]);

    expect($user->budgets()->count())->toBe(1);
});

// Nothing was read to build a limit on, so nothing is written at all.
it('refuses a target for a user with no spending', function () {
    $user = closingUser();
    Account::factory()->for($user)->create(['type' => 'retirement', 'currency_code' => 'EUR']);

    $this->actingAs($user)
        ->postJson('/onboarding/target', ['amount' => 20000, 'warn' => true])
        ->assertStatus(422);

    expect($user->budgets()->count())->toBe(0)
        ->and($user->fresh()->onboarding_answers ?? [])->not->toHaveKey('target');
});

it('reports the target back once it is set', function () {
    Queue::fake();

    $user = closingUser();
    spentEachMonth(spendingAccount($user), RECURRING_CHARGES);

    $this->actingAs($user)->postJson('/onboarding/target', ['amount' => 20000, 'warn' => true]);

    $this->actingAs($user)
        ->getJson('/onboarding/summary')
        ->assertOk()
        ->assertJson(['target' => 20000]);
});

it('rejects a target that is not a positive amount', function (mixed $amount) {
    $user = closingUser();

    $this->actingAs($user)
        ->postJson('/onboarding/target', ['amount' => $amount, 'warn' => true])
        ->assertJsonValidationErrorFor('amount');
})->with([0, -100, 'lots', null]);

it('requires both onboarding and a session', function () {
    $this->getJson('/onboarding/summary')->assertUnauthorized();
    $this->postJson('/onboarding/target', ['amount' => 20000, 'warn' => true])->assertUnauthorized();
});
