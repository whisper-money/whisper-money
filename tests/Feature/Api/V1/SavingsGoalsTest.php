<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function goalsWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

function goalUser(): User
{
    return User::factory()->create(['currency_code' => 'EUR']);
}

function apiMonthlyGoal(User $user, array $attributes = []): SavingsGoal
{
    $goal = SavingsGoal::factory()->monthly(30_000)->create(['user_id' => $user->id, ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

function savingsAccount(User $user, array $attributes = []): Account
{
    return Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings, 'currency_code' => 'EUR', ...$attributes]);
}

function sharedGoalSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

/**
 * @return array<string, mixed>
 */
function newMonthlyGoal(array $overrides = []): array
{
    return ['name' => 'Emergency fund', 'kind' => 'monthly', 'monthly_target_type' => 'amount', 'monthly_target_amount' => 30_000, ...$overrides];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
});

describe('list', function () {
    it('lists the goals of the personal space, newest first, each kind with its own record', function () {
        $user = goalUser();
        $oneOff = SavingsGoal::factory()->create([
            'user_id' => $user->id,
            'name' => 'House',
            'target_amount' => 1_000_000,
            'initial_amount' => 50_000,
            'target_date' => null,
            'created_at' => '2026-09-01 09:30:00',
        ]);
        $this->travelTo(Carbon::parse('2026-09-10'));
        $monthly = apiMonthlyGoal($user, ['name' => 'Emergency fund']);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => savingsAccount($user)->id,
            'currency_code' => 'EUR',
            'amount' => 32_000,
            'transaction_date' => '2026-09-15',
        ])->labels()->attach($monthly->label_id);
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
        SavingsGoal::factory()->create();

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/savings-goals')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$monthly->id, $oneOff->id])
            ->assertJsonPath('data.1.kind', 'one_off')
            ->assertJsonPath('data.1.currency', 'EUR')
            ->assertJsonPath('data.1.target_amount', 1_000_000)
            ->assertJsonPath('data.1.initial_amount', 50_000)
            ->assertJsonPath('data.1.progress.saved', 50_000)
            ->assertJsonPath('data.1.monthly', null)
            ->assertJsonPath('data.1.monthly_target_type', null)
            ->assertJsonPath('data.0.kind', 'monthly')
            ->assertJsonPath('data.0.label_id', $monthly->label_id)
            ->assertJsonPath('data.0.space_id', $user->personalSpace->id)
            ->assertJsonPath('data.0.target_amount', null)
            ->assertJsonPath('data.0.progress', null)
            ->assertJsonPath('data.0.monthly_target_type', 'amount')
            ->assertJsonPath('data.0.monthly_target_amount', 30_000)
            ->assertJsonPath('data.0.monthly.months_met', 1)
            ->assertJsonPath('data.0.monthly.current.month', '2026-10')
            ->assertJsonPath('data.0.monthly.current.days_left', 29);

        expect($response->json('data.0.monthly.history.0'))->toBe([
            'month' => '2026-09', 'target' => 30_000, 'saved' => 32_000, 'difference' => 2_000, 'status' => 'met', 'income_base' => null,
        ]);
    });

    it('pages through the goals with a cursor', function () {
        $user = goalUser();
        $goals = collect(range(1, 3))->map(fn (int $day) => SavingsGoal::factory()->create(['user_id' => $user->id, 'created_at' => "2026-03-0{$day} 10:00:00"]))->reverse()->values();
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/savings-goals?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$goals[0]->id, $goals[1]->id]);

        withHeaders($headers)->getJson('/api/v1/savings-goals?limit=2&starting_after='.$goals[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$goals[2]->id]);

        withHeaders($headers)->getJson('/api/v1/savings-goals?starting_after='.SavingsGoal::factory()->create()->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });

    it('lists a shared space when asked, and refuses one the user cannot access', function () {
        $user = goalUser();
        $owner = goalUser();
        $space = sharedGoalSpace($user, $owner);
        $theirs = SavingsGoal::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson("/api/v1/savings-goals?space={$space->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$theirs->id]);

        withHeaders($headers)->getJson('/api/v1/savings-goals?space='.Space::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });
});

describe('show', function () {
    it('retrieves a goal of the space and 404s on one outside it', function () {
        $user = goalUser();
        $goal = apiMonthlyGoal($user);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson("/api/v1/savings-goals/{$goal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $goal->id)
            ->assertJsonPath('data.monthly.current.month', '2026-10');

        withHeaders($headers)->getJson('/api/v1/savings-goals/'.SavingsGoal::factory()->create()->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('create', function () {
    it('creates a one-off goal with its label', function () {
        $user = goalUser();

        withHeaders(goalsWriter($user))
            ->postJson('/api/v1/savings-goals', ['name' => 'House', 'kind' => 'one_off', 'target_amount' => 1_000_000, 'initial_amount' => 50_000, 'target_date' => '2027-06-30'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'one_off')
            ->assertJsonPath('data.target_date', '2027-06-30')
            ->assertJsonPath('data.progress.saved', 50_000);

        $goal = $user->savingsGoals()->sole();
        expect($goal->target_amount)->toBe(1_000_000)
            ->and($goal->label->name)->toBe('House');
    });

    it('creates a monthly goal whose rule tags the transfers already in the savings account', function () {
        $user = goalUser();
        $savings = savingsAccount($user, ['name' => 'Rainy day']);
        Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $savings->id, 'currency_code' => 'EUR', 'amount' => 25_000, 'transaction_date' => '2026-10-01']);

        withHeaders(goalsWriter($user))
            ->postJson('/api/v1/savings-goals', newMonthlyGoal(['auto_tag_account_id' => $savings->id]))
            ->assertCreated()
            ->assertJsonPath('data.kind', 'monthly')
            ->assertJsonPath('data.auto_tag_account_id', $savings->id)
            ->assertJsonPath('data.monthly.current.month', '2026-10')
            ->assertJsonPath('data.monthly.current.saved', 25_000);

        $goal = $user->savingsGoals()->sole();
        $rule = AutomationRule::query()->where('user_id', $user->id)->sole();
        expect($goal->periods()->count())->toBe(1)
            ->and($rule->labels()->pluck('labels.id')->all())->toBe([$goal->label_id]);
    });

    it('creates the goal in a shared space when asked', function () {
        $user = goalUser();
        $space = sharedGoalSpace($user, goalUser());

        withHeaders(goalsWriter($user))
            ->postJson('/api/v1/savings-goals', [...newMonthlyGoal(), 'space' => $space->id])
            ->assertCreated()
            ->assertJsonPath('data.space_id', $space->id);
    });

    it('refuses invalid input', function (Closure $body, string $field, ?string $message = null) {
        $user = goalUser();
        Label::factory()->create(['user_id' => $user->id, 'name' => 'Taken']);

        $response = withHeaders(goalsWriter($user))
            ->postJson('/api/v1/savings-goals', $body($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        if ($message !== null) {
            expect($response->json('errors')[$field][0])->toBe($message);
        }

        expect($user->savingsGoals()->count())->toBe(0);
    })->with([
        'no kind' => [fn () => newMonthlyGoal(['kind' => null]), 'kind'],
        'no monthly target' => [fn () => newMonthlyGoal(['monthly_target_type' => null]), 'monthly_target_type'],
        'rate over 100' => [fn () => newMonthlyGoal(['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 120]), 'monthly_target_rate'],
        'one-off without total' => [fn () => ['name' => 'Fund', 'kind' => 'one_off'], 'target_amount'],
        'one-off date in the past' => [fn () => ['name' => 'Fund', 'kind' => 'one_off', 'target_amount' => 100, 'target_date' => '2026-01-01'], 'target_date'],
        'field of the other kind' => [fn () => newMonthlyGoal(['target_amount' => 100]), 'target_amount', 'target_amount only applies to one_off goals; this one is monthly.'],
        'auto-tag on a one-off goal' => [fn (User $user) => ['name' => 'Fund', 'kind' => 'one_off', 'target_amount' => 100, 'auto_tag_account_id' => savingsAccount($user)->id], 'auto_tag_account_id', 'auto_tag_account_id only applies to monthly goals; this one is one_off.'],
        'name clash' => [fn () => newMonthlyGoal(['name' => 'Taken']), 'name', 'A label or goal named "Taken" already exists. Pick another name.'],
        'amount over the limit' => [fn () => newMonthlyGoal(['monthly_target_amount' => SavingsGoal::MAX_AMOUNT + 1]), 'monthly_target_amount', 'monthly_target_amount is in minor units of EUR: at most 100000000000, which is €1,000,000,000.00.'],
        'not a savings account' => [fn (User $user) => newMonthlyGoal(['auto_tag_account_id' => Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking])->id]), 'auto_tag_account_id'],
        'account of another space' => [fn (User $user) => newMonthlyGoal(['auto_tag_account_id' => savingsAccount(User::factory()->create())->id]), 'auto_tag_account_id', 'The auto tag account id is not an account in this space.'],
        'unknown field' => [fn () => newMonthlyGoal(['target' => 100]), 'target', 'This endpoint does not take a target field.'],
    ]);

    it('refuses an account another running goal already auto-tags', function () {
        $user = goalUser();
        $savings = savingsAccount($user);
        $headers = goalsWriter($user);

        withHeaders($headers)->postJson('/api/v1/savings-goals', newMonthlyGoal(['auto_tag_account_id' => $savings->id]))->assertCreated();
        $error = withHeaders($headers)
            ->postJson('/api/v1/savings-goals', newMonthlyGoal(['name' => 'Japan trip', 'auto_tag_account_id' => $savings->id]))
            ->assertUnprocessable()
            ->json('errors.auto_tag_account_id.0');

        expect($error)->toContain('already feeds the running monthly goal "Emergency fund"')
            ->and($user->savingsGoals()->count())->toBe(1);
    });

    it('refuses a read-only token', function () {
        $user = goalUser();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/savings-goals', newMonthlyGoal())
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($user->savingsGoals()->count())->toBe(0);
    });

    it('replays a POST with the same idempotency key instead of creating a second goal', function () {
        $user = goalUser();
        $headers = [...goalsWriter($user), 'Idempotency-Key' => 'create-emergency-fund'];

        $first = withHeaders($headers)->postJson('/api/v1/savings-goals', newMonthlyGoal())->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/savings-goals', newMonthlyGoal())->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and($user->savingsGoals()->count())->toBe(1);
    });
});

describe('update', function () {
    it('changes the month in progress and leaves past months alone', function () {
        $this->travelTo(Carbon::parse('2026-09-10'));
        $user = goalUser();
        $goal = apiMonthlyGoal($user, ['name' => 'Emergency fund']);
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
        app(SavingsGoalPeriodService::class)->advance($goal);

        withHeaders(goalsWriter($user))
            ->patchJson("/api/v1/savings-goals/{$goal->id}", [
                'name' => 'Rainy day',
                'monthly_target_type' => 'income_rate',
                'monthly_target_rate' => 15,
                'notify_on_month_end_reminder' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Rainy day')
            ->assertJsonPath('data.monthly_target_type', 'income_rate')
            ->assertJsonPath('data.monthly_target_amount', null)
            ->assertJsonPath('data.notify_on_month_end_reminder', false);

        $goal->refresh();
        $periods = $goal->periods()->orderBy('month')->get();
        expect($goal->label->name)->toBe('Rainy day')
            ->and($periods[0]->target_amount)->toBe(30_000)
            ->and($periods[1]->target_type->value)->toBe('income_rate');
    });

    it('refuses the kind and the fields of the other kind', function (array $body, string $field, string $message) {
        $user = goalUser();
        $goal = apiMonthlyGoal($user, ['name' => 'Fund']);

        $response = withHeaders(goalsWriter($user))
            ->patchJson("/api/v1/savings-goals/{$goal->id}", $body)
            ->assertUnprocessable();

        expect($response->json('errors')[$field][0])->toBe($message)
            ->and($goal->fresh()->isMonthly())->toBeTrue();
    })->with([
        'kind' => [['kind' => 'one_off'], 'kind', 'A goal\'s kind is fixed once created. To switch, create a new goal of the other kind.'],
        'one-off field' => [['target_amount' => 100], 'target_amount', 'target_amount only applies to one_off goals; this one is monthly.'],
        'amount without its type' => [['monthly_target_amount' => 100], 'monthly_target_type', 'The monthly target type field is required when monthly target amount / monthly target rate is present.'],
    ]);

    it('refuses an archived goal, a co-member\'s, someone else\'s and a read-only token', function () {
        $user = goalUser();
        $archived = apiMonthlyGoal($user, ['name' => 'Old']);
        app(SavingsGoalService::class)->archive($archived);
        $owner = goalUser();
        $space = sharedGoalSpace($user, $owner);
        $coMembers = SavingsGoal::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $headers = goalsWriter($user);

        withHeaders($headers)->patchJson("/api/v1/savings-goals/{$archived->id}", ['name' => 'Back'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.savings_goal.0', "Savings goal {$archived->id} is archived and can no longer be changed. Archiving and deleting goals happen in the Whisper Money app.");

        withHeaders($headers)->patchJson("/api/v1/savings-goals/{$coMembers->id}?space={$space->id}", ['name' => 'Mine'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_savings_goal_owner');

        withHeaders($headers)->patchJson('/api/v1/savings-goals/'.SavingsGoal::factory()->create()->id, ['name' => 'Mine'])
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->patchJson('/api/v1/savings-goals/'.apiMonthlyGoal($user)->id, ['name' => 'Fund'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($archived->fresh()->name)->toBe('Old')
            ->and($coMembers->fresh()->name)->not->toBe('Mine');
    });
});
