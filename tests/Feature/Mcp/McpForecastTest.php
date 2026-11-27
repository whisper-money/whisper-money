<?php

use App\Enums\AccountType;
use App\Enums\ForecastFrequency;
use App\Features\Forecast;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetForecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastItem;
use App\Models\Space;
use App\Models\User;
use App\Services\Forecast\ForecastPresenter;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Pennant\Feature;

use function Pest\Laravel\withHeaders;

/**
 * A checking account with 1,000 on 26 Nov 2026, paid 3,000 on the 1st of
 * each month, rent of 1,900 on the 5th, and a yearly insurance of 7,000 every
 * April: April 2027 is the only month it runs short (down to −500).
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);

    $this->checking = mcpForecastAccount($this->user, 'Checking', 100000);
    mcpForecastLine($this->user, $this->checking, 'Salary', 300000, '2026-01-01');
    mcpForecastLine($this->user, $this->checking, 'Rent', -190000, '2026-01-05');
    mcpForecastLine($this->user, $this->checking, 'Car insurance', -700000, '2026-04-10', ForecastFrequency::Yearly);
});

function mcpForecastAccount(User $user, string $name, int $balance, array $attributes = []): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => AccountType::Checking, 'currency_code' => 'EUR', ...$attributes]);
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-11-20', 'balance' => $balance]);

    return $account;
}

function mcpForecastLine(User $user, Account $account, string $name, int $amount, string $anchor, ForecastFrequency $frequency = ForecastFrequency::Monthly): ForecastItem
{
    return ForecastItem::factory()->amount($amount)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'space_id' => $account->space_id,
        'name' => $name,
        'frequency' => $frequency,
        'anchor_date' => $anchor,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function callGetForecast(User $user, array $arguments = []): TestResponse
{
    return WhisperMoneyServer::actingAs($user)->tool(GetForecast::class, $arguments);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function getForecastJson(User $user, array $arguments = []): array
{
    $response = callGetForecast($user, $arguments)->assertOk()->assertHasNoErrors();

    return json_decode((string) (fn () => $this->response->toArray()['result']['content'][0]['text'])->call($response), true);
}

/**
 * The names of the tools tools/list serves $user over the real endpoint.
 *
 * @return list<string>
 */
function mcpListedTools(User $user): array
{
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;

    return array_column(
        withHeaders(['Authorization' => "Bearer {$plain}"])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 100]])
            ->assertOk()
            ->json('result.tools'),
        'name',
    );
}

describe('with the Forecast flag off', function () {
    beforeEach(function () {
        Feature::for($this->user)->deactivate(Forecast::class);
    });

    it('is not listed, and every other tool still is', function () {
        $listed = mcpListedTools($this->user);

        expect($listed)->not->toContain('get_forecast')
            ->and($listed)->toContain('get_cashflow', 'get_net_worth');
    });

    it('cannot be called', function () {
        callGetForecast($this->user)->assertHasErrors();
    });
});

it('is listed with the flag on', function () {
    expect(mcpListedTools($this->user))->toContain('get_forecast');
});

it('gives a Pro user the month in progress and the twelve after it, with the alert month and its cause', function () {
    $forecast = getForecastJson($this->user);

    expect($forecast)->toMatchArray(['today' => '2026-11-26', 'horizon' => 12, 'plan' => 'pro', 'months_shown' => 13])
        ->and($forecast['accounts'])->toHaveCount(1);

    $account = $forecast['accounts'][0];
    $months = collect($account['months'])->keyBy('month');

    expect($account)->toMatchArray(['id' => $this->checking->id, 'name' => 'Checking', 'type' => 'checking', 'currency' => 'EUR', 'alert_threshold' => 0])
        ->and($account['months'])->toHaveCount(13)
        ->and($account['months'][0]['month'])->toBe('2026-11')
        ->and($account['months'][12]['month'])->toBe('2027-11')
        ->and($account['alert'])->toMatchArray(['level' => 'strong', 'strong_month' => '2027-04', 'strong_min' => -50000, 'strong_min_date' => '2027-04-10', 'locked_strong_month_count' => null])
        ->and($account['summary'])->toMatchArray(['lowest_month' => '2027-04', 'lowest_min' => -50000, 'end_month' => '2027-11'])
        ->and($months['2027-04'])->toMatchArray(['alert_level' => 'strong', 'income' => 300000, 'estimated_income' => 0, 'min' => -50000, 'min_date' => '2027-04-10', 'alert_causes' => [['name' => 'Car insurance', 'amount' => 700000]]])
        ->and($months['2027-04'])->toHaveKeys(['expenses', 'estimated_expenses', 'estimated_income', 'transfers', 'close', 'band', 'pessimistic_min'])
        ->and($months['2026-12'])->toMatchArray(['alert_level' => 'ok', 'alert_causes' => []]);
});

it('marks every month below the threshold as strong, not only the worst one, each with its causes', function () {
    mcpForecastLine($this->user, $this->checking, 'Property tax', -500000, '2026-02-10', ForecastFrequency::Yearly);

    $account = getForecastJson($this->user)['accounts'][0];
    $months = collect($account['months'])->keyBy('month');

    expect($account['alert']['strong_month'])->toBe('2027-04')
        ->and($months['2027-02'])->toMatchArray(['alert_level' => 'strong', 'min' => -70000, 'alert_causes' => [['name' => 'Property tax', 'amount' => 500000]]])
        ->and($months['2027-04']['alert_level'])->toBe('strong')
        ->and($months['2027-03']['alert_level'])->toBe('ok');
});

it('never names a weekly line as a cause, since it comes every week like a monthly one', function () {
    mcpForecastLine($this->user, $this->checking, 'Cleaner', -1000, '2026-12-03', ForecastFrequency::Weekly);

    $months = collect(getForecastJson($this->user)['accounts'][0]['months'])->keyBy('month');

    expect($months['2027-04']['alert_causes'])->toBe([['name' => 'Car insurance', 'amount' => 700000]]);
});

it('adds every account up in the user\'s currency, and says when another account covers a shortfall', function () {
    mcpForecastAccount($this->user, 'Savings', 900000);

    $forecast = getForecastJson($this->user);
    $all = $forecast['all_accounts'];

    expect($all)->toMatchArray(['currency' => 'EUR', 'balance_today' => 1000000, 'unconverted_currencies' => []])
        ->and($all['months'])->toHaveCount(13)
        ->and($all['months'][0])->toHaveKeys(['month', 'income', 'expenses', 'min', 'min_date', 'close', 'band'])
        ->and($all['alert'])->toMatchArray(['level' => 'split', 'account_id' => $this->checking->id, 'month' => '2027-04', 'locked_strong_month_count' => null])
        ->and($all['summary']['end_month'])->toBe('2027-11')
        ->and(getForecastJson($this->user, ['account' => $this->checking->id])['all_accounts'])->toBeNull();
});

it('stops at the horizon asked for', function (int $horizon) {
    expect(getForecastJson($this->user, ['horizon' => $horizon])['accounts'][0]['months'])->toHaveCount($horizon + 1);
})->with([3, 6]);

it('refuses a horizon the page does not offer', function () {
    callGetForecast($this->user, ['horizon' => 24])->assertHasErrors();
});

it('lists every included account on its own and can narrow to one', function () {
    $savings = mcpForecastAccount($this->user, 'Savings', 900000);
    $excluded = mcpForecastAccount($this->user, 'Left out', 500);
    ForecastAccountSetting::factory()->create(['account_id' => $excluded->id, 'included' => false]);

    expect(array_column(getForecastJson($this->user)['accounts'], 'name'))->toBe(['Checking', 'Savings'])
        ->and(array_column(getForecastJson($this->user, ['account' => $savings->id])['accounts'], 'id'))->toBe([$savings->id]);

    callGetForecast($this->user, ['account' => $excluded->id])->assertHasErrors(['not one of yours the forecast includes']);
});

it('reaches only the caller\'s own accounts', function () {
    $stranger = User::factory()->create();
    $theirs = mcpForecastAccount($stranger, 'Theirs', 100000);

    callGetForecast($this->user, ['account' => $theirs->id])->assertHasErrors(['not one of yours the forecast includes']);
    expect(array_column(getForecastJson($this->user)['accounts'], 'name'))->toBe(['Checking']);
});

it('forecasts the space asked for, and only the caller\'s own accounts in it', function () {
    $owner = User::factory()->create();
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($this->user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $mine = mcpForecastAccount($this->user, 'Joint mine', 50000, ['space_id' => $space->id]);
    mcpForecastAccount($owner, 'Joint theirs', 50000, ['space_id' => $space->id]);

    expect(array_column(getForecastJson($this->user, ['space' => $space->id])['accounts'], 'id'))->toBe([$mine->id])
        ->and(array_column(getForecastJson($this->user)['accounts'], 'name'))->toBe(['Checking']);

    callGetForecast($this->user, ['space' => Space::factory()->create()->id])->assertHasErrors();
});

it('answers with no accounts when there is nothing to forecast', function () {
    $this->checking->delete();

    expect(getForecastJson($this->user)['accounts'])->toBe([]);
});

it('refuses a user without a paid plan, like every MCP tool', function () {
    $free = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    Feature::for($free)->activate(Forecast::class);

    callGetForecast($free)->assertHasErrors(['paid (Standard) plan is required']);
});

it('never forecasts more than the month in progress and the next one without Pro', function () {
    $free = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $account = mcpForecastAccount($free, 'Checking', 100000);
    mcpForecastLine($free, $account, 'Salary', 300000, '2026-01-01');
    mcpForecastLine($free, $account, 'Rent', -190000, '2026-01-05');
    mcpForecastLine($free, $account, 'Car insurance', -700000, '2026-04-10', ForecastFrequency::Yearly);

    $overview = app(ForecastPresenter::class)->overview($free, $account->space_id, null, 12);
    $forecast = $overview['accounts'][0];

    expect($overview['isPro'])->toBeFalse()
        ->and(array_column($forecast['months'], 'month'))->toBe(['2026-11', '2026-12'])
        ->and($forecast['alert']['level'])->toBe('ok')
        ->and($forecast['causes'])->toBe([])
        ->and($forecast['lockedAlertCount'])->toBe(1)
        ->and(array_column($overview['all']['months'], 'month'))->toBe(['2026-11', '2026-12'])
        ->and($overview['all']['lockedAlertCount'])->toBe(1);
});
