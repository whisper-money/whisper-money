<?php

use App\Enums\CategoryType;
use App\Http\ApiV1\Listings;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CreditCards\CreditCardStatementService;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\Widgets\Gateway\DashboardVariables;
use App\Services\Widgets\Gateway\EndpointRegistry;
use App\Services\Widgets\Gateway\WidgetDataGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * A user with one of everything a widget can read, all in their personal
 * space: an account with a balance, a credit card, categorized and labelled
 * transactions, a budget, a monthly savings goal and an automation rule.
 *
 * @return array{user: User, account: Account, card: Account, category: Category, label: Label, transaction: Transaction, budget: Budget, goal: SavingsGoal, rule: AutomationRule}
 */
function gatewayFixture(): array
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $owned = ['user_id' => $user->id];

    $account = Account::factory()->create([...$owned, 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-10-01', 'balance' => 150_000]);
    $card = Account::factory()->creditCard()->create([...$owned, 'currency_code' => 'EUR']);
    $category = Category::factory()->create([...$owned, 'type' => CategoryType::Expense]);
    $label = Label::factory()->create($owned);

    $transaction = Transaction::factory()->create([
        ...$owned,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Coffee beans',
        'amount' => -4_200,
        'currency_code' => 'EUR',
        'transaction_date' => '2026-10-05',
    ]);
    $transaction->labels()->attach($label->id);
    Transaction::factory()->create([...$owned, 'account_id' => $card->id, 'category_id' => $category->id, 'amount' => -1_500, 'currency_code' => 'EUR', 'transaction_date' => '2026-10-08']);

    $budget = Budget::factory()->monthly()->forCategories($category)->create($owned);
    $goal = SavingsGoal::factory()->monthly()->create([...$owned, 'created_at' => '2026-08-15']);
    app(SavingsGoalPeriodService::class)->advance($goal);
    $rule = AutomationRule::factory()->create($owned);

    return compact('user', 'account', 'card', 'category', 'label', 'transaction', 'budget', 'goal', 'rule');
}

/**
 * One call resolved by the gateway for October 2026.
 *
 * @param  array<string, mixed>  $params
 * @return array{status: int, body: array<mixed>}
 */
function gatewayCall(User $user, string $endpoint, array $params = [], ?string $paginate = null): array
{
    $call = ['endpoint' => $endpoint, 'params' => $params, ...($paginate === null ? [] : ['paginate' => $paginate])];

    return app(WidgetDataGateway::class)->resolve($user, ['result' => $call], gatewayOctober())['result'];
}

function gatewayOctober(): DashboardVariables
{
    return new DashboardVariables(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
}

/**
 * The same call answered by `/api/v1` over HTTP, with a real token: the path
 * parameters filled into the path and the rest sent as the query string.
 *
 * @param  array<string, mixed>  $params
 * @return array{status: int, body: array<mixed>}
 */
function gatewayApiCall(User $user, string $endpoint, array $params = []): array
{
    $path = preg_replace_callback('/\{(\w+)\}/', function (array $match) use (&$params): string {
        $value = $params[$match[1]];
        unset($params[$match[1]]);

        return $value;
    }, substr($endpoint, strlen('GET ')));

    $response = withHeaders(apiV1Bearer($user))->getJson('/api/v1'.$path.($params === [] ? '' : '?'.http_build_query($params)));

    return ['status' => $response->status(), 'body' => $response->json()];
}

/**
 * Every registered endpoint, with the parameters it is read with: a success
 * and, where it takes any, a failure.
 *
 * @return array<string, array{0: string, 1: Closure(array<string, mixed>): array<string, mixed>, 2: int}>
 */
function gatewayParityCases(): array
{
    return [
        'spaces' => ['GET /spaces', fn (array $f): array => [], 200],
        'accounts' => ['GET /accounts', fn (array $f): array => [], 200],
        'accounts, one page' => ['GET /accounts', fn (array $f): array => ['limit' => 1], 200],
        'accounts, next page' => ['GET /accounts', fn (array $f): array => ['limit' => 1, 'starting_after' => $f['card']->id], 200],
        'accounts, bad cursor' => ['GET /accounts', fn (array $f): array => ['starting_after' => (string) Str::uuid()], 400],
        'accounts, inaccessible space' => ['GET /accounts', fn (array $f): array => ['space' => (string) Str::uuid()], 422],
        'account' => ['GET /accounts/{account}', fn (array $f): array => ['account' => $f['account']->id], 200],
        'account, missing' => ['GET /accounts/{account}', fn (array $f): array => ['account' => (string) Str::uuid()], 404],
        'balances' => ['GET /accounts/{account}/balances', fn (array $f): array => ['account' => $f['account']->id], 200],
        'transactions' => ['GET /transactions', fn (array $f): array => ['from' => '2026-10-01', 'to' => '2026-10-31'], 200],
        'transactions, filtered' => ['GET /transactions', fn (array $f): array => ['category_id' => $f['category']->id, 'label_ids' => [$f['label']->id], 'max_amount' => 0, 'query' => '  coffee  '], 200],
        'transactions, blank query' => ['GET /transactions', fn (array $f): array => ['query' => '   '], 422],
        'transactions, invalid' => ['GET /transactions', fn (array $f): array => ['account_id' => (string) Str::uuid(), 'from' => 'yesterday', 'min_amount' => 'many'], 422],
        'transaction' => ['GET /transactions/{transaction}', fn (array $f): array => ['transaction' => $f['transaction']->id], 200],
        'categories' => ['GET /categories', fn (array $f): array => [], 200],
        'category' => ['GET /categories/{category}', fn (array $f): array => ['category' => $f['category']->id], 200],
        'labels' => ['GET /labels', fn (array $f): array => [], 200],
        'label' => ['GET /labels/{label}', fn (array $f): array => ['label' => $f['label']->id], 200],
        'automation rules' => ['GET /automation-rules', fn (array $f): array => [], 200],
        'automation rule' => ['GET /automation-rules/{automation_rule}', fn (array $f): array => ['automation_rule' => $f['rule']->id], 200],
        'budgets' => ['GET /budgets', fn (array $f): array => [], 200],
        'budget' => ['GET /budgets/{budget}', fn (array $f): array => ['budget' => $f['budget']->id], 200],
        'savings goals' => ['GET /savings-goals', fn (array $f): array => [], 200],
        'savings goal' => ['GET /savings-goals/{savings_goal}', fn (array $f): array => ['savings_goal' => $f['goal']->id], 200],
        'achievements' => ['GET /achievements', fn (array $f): array => [], 200],
        'cashflow' => ['GET /analytics/cashflow', fn (array $f): array => ['from' => '2026-10-01', 'to' => '2026-10-31'], 200],
        'cashflow, too long' => ['GET /analytics/cashflow', fn (array $f): array => ['from' => '2020-01-01', 'to' => '2026-10-31'], 422],
        'net worth' => ['GET /analytics/net-worth', fn (array $f): array => ['from' => '2026-01-01', 'to' => '2026-10-31'], 200],
        'net worth, daily' => ['GET /analytics/net-worth', fn (array $f): array => ['from' => '2026-10-01', 'to' => '2026-10-31', 'granularity' => 'daily'], 200],
        'spending by category' => ['GET /analytics/spending-by-category', fn (array $f): array => ['from' => '2026-10-01', 'to' => '2026-10-31'], 200],
        'spending by category, someone else\'s parent' => ['GET /analytics/spending-by-category', fn (array $f): array => ['from' => '2026-10-01', 'to' => '2026-10-31', 'parent_category_id' => Category::factory()->create()->id], 422],
        'spending by label' => ['GET /analytics/spending-by-label', fn (array $f): array => ['from' => '2026-10-01', 'to' => '2026-10-31'], 200],
        'spending by label, missing range' => ['GET /analytics/spending-by-label', fn (array $f): array => [], 422],
        'credit card usage' => ['GET /analytics/credit-card-usage', fn (array $f): array => [], 200],
        'monthly savings' => ['GET /analytics/monthly-savings', fn (array $f): array => [], 200],
        'aggregate' => ['GET /analytics/aggregate', fn (array $f): array => ['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => 'month,category', 'types' => ['expense'], 'include_subcategories' => 'false'], 200],
        'aggregate, invalid' => ['GET /analytics/aggregate', fn (array $f): array => ['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['day', 'month'], 'metrics' => ['median']], 422],
    ];
}

beforeEach(function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
});

describe('parity with the API', function () {
    it('answers what the API answers', function (string $endpoint, Closure $params, int $status) {
        $fixture = gatewayFixture();
        $params = $params($fixture);
        $answer = gatewayCall($fixture['user'], $endpoint, $params);

        expect($answer['status'])->toBe($status)
            ->and($answer)->toBe(gatewayApiCall($fixture['user'], $endpoint, $params));
    })->with(gatewayParityCases());

    it('registers every GET endpoint of the API', function () {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => in_array('api-v1', $route->gatherMiddleware(), true) && in_array('GET', $route->methods(), true))
            ->map(fn (RoutingRoute $route): string => 'GET '.Str::after($route->uri(), 'api/v1'))
            ->values()
            ->all();

        expect(array_keys(app(EndpointRegistry::class)->all()))->toEqualCanonicalizing($routes);
    });

    it('is checked on every registered endpoint', function () {
        expect(array_keys(app(EndpointRegistry::class)->all()))
            ->toEqualCanonicalizing(collect(gatewayParityCases())->pluck(0)->unique()->values()->all());
    });
});

it('refuses an endpoint that is not registered', function () {
    $user = User::factory()->create();

    expect(gatewayCall($user, 'GET /users'))->toBe(['status' => 404, 'body' => [
        'message' => 'GET /users is not available to widgets.',
        'code' => 'unknown_endpoint',
    ]])->and(gatewayCall($user, 'DELETE /transactions/{transaction}', ['transaction' => (string) Str::uuid()])['body']['code'])->toBe('unknown_endpoint');
});

it('answers invalid parameters with the API\'s validation errors', function () {
    $user = User::factory()->create();

    expect(gatewayCall($user, 'GET /analytics/net-worth', ['from' => '2026-10-31', 'to' => '2026-10-01', 'granularity' => 'hourly']))->toBe(['status' => 422, 'body' => [
        'message' => 'The to field must be a date after or equal to from. (and 1 more error)',
        'errors' => [
            'to' => ['The to field must be a date after or equal to from.'],
            'granularity' => ['The selected granularity is invalid.'],
        ],
    ]]);
});

describe('own rows only', function () {
    it('does not read another user\'s rows', function () {
        $fixture = gatewayFixture();
        $stranger = User::factory()->create();

        expect(gatewayCall($stranger, 'GET /transactions')['body']['data'])->toBe([])
            ->and(gatewayCall($stranger, 'GET /accounts/{account}', ['account' => $fixture['account']->id])['status'])->toBe(404)
            ->and(gatewayCall($stranger, 'GET /budgets/{budget}', ['budget' => $fixture['budget']->id])['status'])->toBe(404)
            ->and(gatewayCall($stranger, 'GET /analytics/aggregate', ['from' => '2026-10-01', 'to' => '2026-10-31', 'account_ids' => [$fixture['account']->id]])['body']['errors'])
            ->toBe(['account_ids.0' => ['The account_ids.0 is not an account of yours in this space.']]);
    });

    it('does not read another member\'s rows in a shared space', function () {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
        $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
        $ownersAccount = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        Transaction::factory()->create(['user_id' => $owner->id, 'account_id' => $ownersAccount->id, 'space_id' => $space->id, 'transaction_date' => '2026-10-05']);
        $membersAccount = Account::factory()->create(['user_id' => $member->id, 'space_id' => $space->id]);

        expect(collect(gatewayCall($member, 'GET /accounts', ['space' => $space->id])['body']['data'])->pluck('id')->all())->toBe([$membersAccount->id])
            ->and(gatewayCall($member, 'GET /transactions', ['space' => $space->id], 'all')['body'])->toBe(['data' => [], 'truncated' => false])
            ->and(gatewayCall($member, 'GET /accounts/{account}', ['account' => $ownersAccount->id, 'space' => $space->id])['status'])->toBe(404);
    });
});

describe('reading a whole list', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $this->user->id]);
        Transaction::factory()->count(5)->sequence(fn ($sequence) => ['transaction_date' => '2026-10-0'.($sequence->index + 1)])
            ->create(['user_id' => $this->user->id, 'account_id' => $account->id]);
    });

    it('reads every row past the API\'s page size, in the list\'s order', function () {
        $page = gatewayCall($this->user, 'GET /transactions', ['limit' => 2]);
        $all = gatewayCall($this->user, 'GET /transactions', [], 'all');

        expect($page['body']['has_more'])->toBeTrue()
            ->and($all)->toBe(['status' => 200, 'body' => [
                'data' => gatewayApiCall($this->user, 'GET /transactions', ['limit' => 100])['body']['data'],
                'truncated' => false,
            ]])
            ->and(array_column($all['body']['data'], 'transaction_date'))->toBe(['2026-10-05', '2026-10-04', '2026-10-03', '2026-10-02', '2026-10-01']);
    });

    it('stops at the row cap and marks the result truncated', function () {
        config(['widgets.gateway.max_rows' => 3]);

        $all = gatewayCall($this->user, 'GET /transactions', [], 'all');

        expect($all['body']['truncated'])->toBeTrue()
            ->and(array_column($all['body']['data'], 'transaction_date'))->toBe(['2026-10-05', '2026-10-04', '2026-10-03']);
    });

    it('reads the list in chunks', function () {
        $listing = fn () => app(Listings::class)->transactions($this->user, $this->user->personalSpace, []);
        $request = Request::create('/api/v1/transactions');

        expect($listing()->all($request, 4, chunkSize: 2))->toBe(['data' => array_slice(gatewayCall($this->user, 'GET /transactions', [], 'all')['body']['data'], 0, 4), 'truncated' => true])
            ->and($listing()->all($request, 10, chunkSize: 2)['truncated'])->toBeFalse()
            ->and($listing()->all($request, 10, chunkSize: 2)['data'])->toHaveCount(5)
            ->and($listing()->all($request, 5, chunkSize: 5))->toMatchArray(['truncated' => false]);
    });

    it('still validates the filters', function () {
        expect(gatewayCall($this->user, 'GET /transactions', ['from' => 'soon'], 'all')['status'])->toBe(422);
    });
});

it('fills the dashboard variables into the parameters', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'transaction_date' => '2026-09-30']);
    $october = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'transaction_date' => '2026-10-01']);

    $answer = gatewayCall($user, 'GET /transactions', ['from' => '{{range.from}}', 'to' => '{{ range.to }}']);

    expect(array_column($answer['body']['data'], 'id'))->toBe([$october->id]);
});

describe('limits', function () {
    it('refuses calls past the gateway\'s own limit per user', function () {
        config(['widgets.gateway.calls_per_minute' => 2]);
        $user = User::factory()->create();
        $other = User::factory()->create();

        $answers = app(WidgetDataGateway::class)->resolve($user, [
            'labels' => ['endpoint' => 'GET /labels'],
            'categories' => ['endpoint' => 'GET /categories'],
            'spaces' => ['endpoint' => 'GET /spaces'],
        ], gatewayOctober());

        expect(array_column($answers, 'status'))->toBe([200, 200, 429])
            ->and($answers['spaces']['body'])->toMatchArray(['code' => 'too_many_requests', 'retry_after' => 60])
            ->and(gatewayCall($other, 'GET /labels')['status'])->toBe(200);
    });

    it('does not spend the public API\'s budget', function () {
        config(['widgets.gateway.calls_per_minute' => 100]);
        $user = User::factory()->create();

        foreach (range(1, 61) as $call) {
            gatewayCall($user, 'GET /labels', ['limit' => $call % 100 + 1]);
        }

        expect(gatewayApiCall($user, 'GET /labels')['status'])->toBe(200);
    });

    it('reads a call repeated within one resolution once, counting it once, and caches nothing across resolutions', function () {
        config(['widgets.gateway.calls_per_minute' => 3]);
        $user = User::factory()->create();
        $call = ['endpoint' => 'GET /analytics/aggregate', 'params' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['month']]];
        $queries = function (array $data) use ($user): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $answers = app(WidgetDataGateway::class)->resolve($user, $data, gatewayOctober());

            return [$answers, count(DB::getQueryLog())];
        };

        $queries(['warm_up' => $call]);
        [, $queriesForOne] = $queries(['only' => $call]);
        [$twice, $queriesForTwo] = $queries([
            'first' => ['endpoint' => 'GET /analytics/aggregate', 'params' => ['from' => '{{range.from}}', 'to' => '{{range.to}}', 'group_by' => ['month']]],
            'second' => ['endpoint' => 'GET /analytics/aggregate', 'params' => ['group_by' => ['month'], 'to' => '2026-10-31', 'from' => '2026-10-01']],
        ]);
        [$past] = $queries(['again' => $call]);

        expect($twice['first'])->toBe($twice['second'])
            ->and($twice['first']['status'])->toBe(200)
            ->and($queriesForTwo)->toBe($queriesForOne)
            ->and($past['again']['status'])->toBe(429);
    });
});

it('answers an unexpected error as a 500 for that call only, and reports it', function () {
    Exceptions::fake();
    $this->mock(CreditCardStatementService::class)->shouldReceive('liveUsageFor')->andThrow(new RuntimeException('Statement exploded'));
    $user = User::factory()->create();

    $answers = app(WidgetDataGateway::class)->resolve($user, [
        'cards' => ['endpoint' => 'GET /analytics/credit-card-usage'],
        'labels' => ['endpoint' => 'GET /labels'],
    ], gatewayOctober());

    expect($answers['cards'])->toBe(['status' => 500, 'body' => ['message' => 'Internal Server Error', 'code' => 'internal_server_error']])
        ->and($answers['labels']['status'])->toBe(200);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Statement exploded');
});

it('resolves without HTTP and leaves the current request and sign-in alone', function () {
    $user = User::factory()->create();
    $current = request();

    $answer = gatewayCall($user, 'GET /spaces');

    expect($answer['status'])->toBe(200)
        ->and(request())->toBe($current)
        ->and(auth()->check())->toBeFalse();
});
