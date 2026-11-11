<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

beforeEach(function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
});

/**
 * A member of a space owned by someone else: the case where a bare space
 * scope would leak the owner's rows to the member.
 *
 * @return array{user: User, owner: User, space: Space}
 */
function figuresSharedSpace(): array
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $owner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return compact('user', 'owner', 'space');
}

/**
 * A dashboard prop as the web page receives it.
 */
function figuresDashboardProp(User $user, string $prop): mixed
{
    app('auth')->forgetGuards();

    return test()->actingAs($user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => $prop,
    ])->assertOk()->json("props.{$prop}");
}

function figuresLabelledExpense(User $user, Label $label, int $amount, string $date, ?Space $space = null): void
{
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', ...($space === null ? [] : ['space_id' => $space->id])]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -$amount,
        'currency_code' => 'EUR',
        'transaction_date' => $date,
    ])->labels()->attach($label->id);
}

function figuresCard(User $user, string $name, int $purchase, ?int $limit, array $attributes = []): Account
{
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id, 'name' => $name, 'currency_code' => 'EUR', ...$attributes]);

    if ($limit !== null) {
        CreditCardDetail::factory()->create(['account_id' => $card->id, 'statement_closing_date' => null, 'payment_due_date' => null, 'credit_limit' => $limit]);
    }

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $card->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense])->id,
        'currency_code' => 'EUR',
        'amount' => -$purchase,
        'transaction_date' => '2026-10-10',
    ]);

    return $card;
}

function figuresMonthlyGoal(User $user, array $attributes = []): SavingsGoal
{
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id, 'created_at' => '2026-08-15', ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

function figuresContribution(User $user, SavingsGoal $goal, int $amount, string $date): void
{
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings])->id,
        'amount' => $amount,
        'transaction_date' => $date,
    ])->labels()->attach($goal->label_id);
}

describe('spending by label', function () {
    it('ranks the labels by spending against the previous period', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $trip = Label::factory()->create(['user_id' => $user->id, 'name' => 'Trip']);
        $gifts = Label::factory()->create(['user_id' => $user->id, 'name' => 'Gifts']);
        figuresLabelledExpense($user, $trip, 30_000, '2026-10-03');
        figuresLabelledExpense($user, $trip, 12_000, '2026-09-20');
        figuresLabelledExpense($user, $gifts, 5_000, '2026-10-08');

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/spending-by-label?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertExactJson(['data' => [
                'currency' => 'EUR',
                'total' => 35_000,
                'previous_from' => '2026-09-01',
                'previous_to' => '2026-09-30',
                'labels' => [
                    ['label_id' => $trip->id, 'name' => 'Trip', 'color' => $trip->color, 'amount' => 30_000, 'previous_amount' => 12_000],
                    ['label_id' => $gifts->id, 'name' => 'Gifts', 'color' => $gifts->color, 'amount' => 5_000, 'previous_amount' => 0],
                ],
            ]]);
    });

    it('reads the same figures as the dashboard\'s top labels', function () {
        $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
        $trip = Label::factory()->create(['user_id' => $user->id]);
        figuresLabelledExpense($user, $trip, 30_000, '2026-10-03');
        figuresLabelledExpense($user, $trip, 4_000, '2026-09-01');

        $api = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/spending-by-label?from=2026-09-16&to=2026-10-15')
            ->assertOk()
            ->json('data.labels');
        $web = figuresDashboardProp($user, 'topLabels');

        expect($api)->toBe(array_map(fn (array $label): array => [
            'label_id' => $label['id'],
            'name' => $label['name'],
            'color' => $label['color'],
            'amount' => $label['amount'],
            'previous_amount' => $label['previous_amount'],
        ], $web))->and($api[0]['previous_amount'])->toBe(4_000);
    });

    it('leaves out another member\'s spending in a shared space', function () {
        ['user' => $user, 'owner' => $owner, 'space' => $space] = figuresSharedSpace();
        $theirs = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        figuresLabelledExpense($owner, $theirs, 9_999, '2026-10-03', $space);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/spending-by-label?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.labels', []);
    });

    it('needs a valid range', function () {
        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson('/api/v1/analytics/spending-by-label?from=2026-10-31&to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    });
});

describe('credit card usage', function () {
    it('gives each live credit card\'s usage with its daily line', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $visa = figuresCard($user, 'Visa', 124_000, limit: 300_000);
        figuresCard($user, 'Old', 1_000, limit: null, attributes: ['archived_at' => '2026-09-01']);
        Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/credit-card-usage')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account_id', $visa->id)
            ->assertJsonPath('data.0.name', 'Visa')
            ->assertJsonPath('data.0.currency', 'EUR')
            ->assertJsonPath('data.0.limit', 300_000)
            ->assertJsonPath('data.0.used', 124_000)
            ->assertJsonPath('data.0.available', 176_000)
            ->assertJsonPath('data.0.period_from', '2026-10-01')
            ->assertJsonPath('data.0.period_to', '2026-10-31');

        expect($response->json('data.0.daily'))->toHaveCount(15)
            ->and($response->json('data.0.daily.8'))->toBe(['date' => '2026-10-09', 'used' => 0])
            ->and($response->json('data.0.daily.14'))->toBe(['date' => '2026-10-15', 'used' => 124_000]);
    });

    it('reads the same figures as the dashboard\'s cards', function () {
        $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
        figuresCard($user, 'Visa', 124_000, limit: 300_000);
        figuresCard($user, 'Amex', 5_000, limit: null);

        $api = withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/credit-card-usage')->assertOk()->json('data');
        $web = figuresDashboardProp($user, 'creditCardUsage');

        expect(collect($api)->mapWithKeys(fn (array $card): array => [
            $card['account_id'] => Arr::except($card, ['account_id', 'name', 'currency']),
        ])->all())->toEqual($web);
    });

    it('leaves out another member\'s card in a shared space', function () {
        ['user' => $user, 'owner' => $owner, 'space' => $space] = figuresSharedSpace();
        figuresCard($owner, 'Theirs', 9_999, limit: 100_000, attributes: ['space_id' => $space->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/credit-card-usage')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    });
});

describe('monthly savings', function () {
    it('adds up this month across the running goals and judges last month', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $fund = figuresMonthlyGoal($user, ['name' => 'Fund']);
        $trip = figuresMonthlyGoal($user, ['name' => 'Trip', 'created_at' => '2026-08-20']);
        SavingsGoal::factory()->monthly()->archived()->create(['user_id' => $user->id]);
        figuresContribution($user, $fund, 25_000, '2026-10-02');
        figuresContribution($user, $fund, 30_000, '2026-09-05');
        figuresContribution($user, $trip, 10_000, '2026-10-05');

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/monthly-savings')
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.month', '2026-10')
            ->assertJsonPath('data.saved', 35_000)
            ->assertJsonPath('data.target', 60_000)
            ->assertJsonPath('data.days_left', 17)
            ->assertJsonCount(2, 'data.goals')
            ->assertJsonPath('data.goals.0', ['id' => $fund->id, 'name' => 'Fund', 'current' => [
                'month' => '2026-10', 'target' => 30_000, 'saved' => 25_000, 'difference' => -5_000,
                'status' => 'in_progress', 'income_base' => null, 'remaining' => 5_000, 'days_left' => 17,
            ]])
            ->assertJsonPath('data.goals.1.id', $trip->id)
            ->assertJsonPath('data.last_month', ['month' => '2026-09', 'met' => 1, 'total' => 2]);
    });

    it('leaves a partial first month out of the sums', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00'));
        $late = figuresMonthlyGoal($user, ['created_at' => '2026-10-28']);
        figuresContribution($user, $late, 5_000, '2026-10-28');

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/monthly-savings')
            ->assertOk()
            ->assertJsonPath('data.saved', 0)
            ->assertJsonPath('data.target', 0)
            ->assertJsonPath('data.goals.0.current.status', 'partial')
            ->assertJsonPath('data.goals.0.current.saved', 5_000)
            ->assertJsonPath('data.last_month', null);
    });

    it('reads the same goals and verdicts as the dashboard\'s card', function () {
        $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
        $fund = figuresMonthlyGoal($user);
        figuresContribution($user, $fund, 25_000, '2026-10-02');

        $api = withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/monthly-savings')->assertOk()->json('data');
        $goals = figuresDashboardProp($user, 'monthlySavingsGoals');

        expect($api['last_month'])->toBe(figuresDashboardProp($user, 'monthlySavingsLastMonth'))
            ->and(array_column($api['goals'], 'id'))->toBe(array_column($goals, 'id'))
            ->and($api['goals'][0]['current'])->toEqual(Arr::only($goals[0]['monthly']['current'], array_keys($api['goals'][0]['current'])));
    });

    it('leaves out another member\'s goals in a shared space', function () {
        ['user' => $user, 'owner' => $owner, 'space' => $space] = figuresSharedSpace();
        figuresMonthlyGoal($owner, ['space_id' => $space->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/monthly-savings')
            ->assertOk()
            ->assertJsonPath('data.saved', 0)
            ->assertJsonPath('data.target', 0)
            ->assertJsonPath('data.goals', [])
            ->assertJsonPath('data.last_month', null);
    });
});
