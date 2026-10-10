<?php

use App\Enums\AccountType;
use App\Mail\Drip\MonthlySummaryEmail;
use App\Models\Account;
use App\Models\MonthlySummary;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\CardRenderer;
use App\Services\MonthlySummary\EmailPresenter;
use App\Services\MonthlySummary\ReportPresenter;
use App\Services\MonthlySummary\SummaryBuilder;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Support\Carbon;

function surfacesUser(): User
{
    return User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
}

function surfacesGoal(User $user, array $attributes = [], int $target = 30000): SavingsGoal
{
    $goal = SavingsGoal::factory()->monthly($target)->create(['user_id' => $user->id, ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

function surfacesSave(SavingsGoal $goal, int $amount, string $date): void
{
    Transaction::factory()->create([
        'user_id' => $goal->user_id,
        'account_id' => Account::factory()->create(['user_id' => $goal->user_id, 'type' => AccountType::Savings, 'currency_code' => 'EUR'])->id,
        'currency_code' => 'EUR',
        'amount' => $amount,
        'transaction_date' => $date,
    ])->labels()->attach($goal->label_id);
}

/**
 * Two goals started in August: one met September, the other fell short.
 *
 * @return array{0: User, 1: SavingsGoal, 2: SavingsGoal}
 */
function surfacesSeptember(): array
{
    test()->travelTo(Carbon::parse('2026-08-10'));
    $user = surfacesUser();
    $fund = surfacesGoal($user, ['name' => 'Emergency fund']);
    $trip = surfacesGoal($user, ['name' => 'Japan trip'], target: 20000);

    surfacesSave($fund, 30000, '2026-08-12');
    surfacesSave($fund, 32000, '2026-09-12');
    surfacesSave($trip, 15000, '2026-09-12');

    test()->travelTo(Carbon::parse('2026-10-03'));
    app(SavingsGoalPeriodService::class)->advance($fund);
    app(SavingsGoalPeriodService::class)->advance($trip);

    return [$user, $fund, $trip];
}

test('the dashboard lists running monthly goals only', function () {
    $this->travelTo(Carbon::parse('2026-10-03'));
    $user = surfacesUser();
    $running = surfacesGoal($user, ['name' => 'Emergency fund']);
    app(SavingsGoalService::class)->archive(surfacesGoal($user, ['name' => 'Old one']));
    SavingsGoal::factory()->create(['user_id' => $user->id, 'name' => 'House']);

    $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'monthlySavingsGoals',
    ])
        ->assertOk()
        ->assertJsonCount(1, 'props.monthlySavingsGoals')
        ->assertJsonPath('props.monthlySavingsGoals.0.id', $running->id)
        ->assertJsonPath('props.monthlySavingsGoals.0.monthly.current.month', '2026-10');
});

test('the dashboard sends no monthly goals to a user without any', function () {
    $user = surfacesUser();

    $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'monthlySavingsGoals',
    ])
        ->assertOk()
        ->assertJsonCount(0, 'props.monthlySavingsGoals');
});

test('the cashflow card adds every goal up for the month with the months before', function () {
    [$user] = surfacesSeptember();

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-09')
        ->assertJsonPath('data.saved', 47000)
        ->assertJsonPath('data.target', 50000)
        ->assertJsonPath('data.difference', -3000)
        ->assertJsonPath('data.met', 1)
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.status', 'missed')
        ->assertJsonPath('data.history.0.month', '2026-08')
        ->assertJsonPath('data.history.0.saved', 30000)
        ->assertJsonPath('data.history.1.month', '2026-09')
        ->assertJsonMissingPath('data.history.0.goals');
});

test('the cashflow card is empty for a month no goal had', function () {
    [$user] = surfacesSeptember();

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-06')
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('the cashflow card never shows another user goals', function () {
    surfacesSeptember();

    $this->actingAs(surfacesUser())->getJson('/api/cashflow/monthly-savings?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('the cashflow card wants a month', function (string $month) {
    $this->actingAs(surfacesUser())->getJson('/api/cashflow/monthly-savings?month='.$month)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('month');
})->with(['', '2026-13', '2026-09-01', 'september']);

test('the monthly summary judges each goal against that month', function () {
    [$user] = surfacesSeptember();

    $payload = app(SummaryBuilder::class)->build($user, Carbon::parse('2026-09-01'), complete: true);

    expect($payload['monthly_goals'])->toMatchArray([
        'total' => 2,
        'met' => 1,
        'saved' => 47000,
        'target' => 50000,
    ])
        ->and($payload['monthly_goals']['goals'])->toBe([
            ['name' => 'Emergency fund', 'saved' => 32000, 'target' => 30000, 'difference' => 2000, 'met' => true, 'streak' => 2],
            ['name' => 'Japan trip', 'saved' => 15000, 'target' => 20000, 'difference' => -5000, 'met' => false, 'streak' => 0],
        ]);
});

test('the monthly summary has no monthly goals section without monthly goals', function () {
    $this->travelTo(Carbon::parse('2026-10-03'));
    $user = surfacesUser();
    SavingsGoal::factory()->create(['user_id' => $user->id]);

    $payload = app(SummaryBuilder::class)->build($user, Carbon::parse('2026-09-01'), complete: true);

    expect($payload['monthly_goals'])->toBeNull();
});

/**
 * A sent summary whose payload carries a monthly goals section.
 */
function surfacesSummary(array $overrides = []): MonthlySummary
{
    $user = surfacesUser();
    $summary = MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    $summary->forceFill(['payload' => array_replace($summary->payload, [
        'monthly_goals' => [
            'total' => 2,
            'met' => 1,
            'saved' => 47000,
            'target' => 50000,
            'goals' => [
                ['name' => 'Emergency fund', 'saved' => 32000, 'target' => 30000, 'difference' => 2000, 'met' => true, 'streak' => 2],
                ['name' => 'Japan <trip>', 'saved' => 15000, 'target' => 20000, 'difference' => -5000, 'met' => false, 'streak' => 0],
            ],
        ],
    ], $overrides)])->save();

    return $summary->fresh();
}

test('the report says how each monthly goal landed', function () {
    $summary = surfacesSummary();

    $texts = array_column(app(ReportPresenter::class)->present($summary, 'en')['rows'], 'text');
    $row = collect($texts)->first(fn (string $text): bool => str_contains($text, 'monthly savings goals'));

    expect($row)->toContain('You met <strong>1</strong> of <strong>2</strong> monthly savings goals')
        ->toContain('Emergency fund (<strong>+€20.00</strong>)')
        ->toContain('Japan &lt;trip&gt; (<strong>-€50.00</strong>)');
});

test('the report leaves monthly goals out of an older summary', function () {
    $summary = surfacesSummary(['monthly_goals' => null]);

    $texts = array_column(app(ReportPresenter::class)->present($summary, 'en')['rows'], 'text');

    expect(implode(' ', $texts))->not->toContain('monthly savings goals');
});

test('the summary email counts monthly goals without amounts, after the existing tiles', function () {
    $summary = surfacesSummary([
        'has_history' => false,
        'budgets' => ['total' => 0, 'met' => 0, 'overspent' => []],
    ]);

    $kpis = app(EmailPresenter::class)->present($summary->user, $summary, 'en', false)['kpis'];

    expect(array_column($kpis, 'label'))->toBe(['Trip to Japan', 'Monthly savings goals'])
        ->and($kpis[1])->toMatchArray(['value' => '1 of 2', 'sub' => 'met']);

    // The email draws a card through Chromium; what is under test is the copy.
    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('url')->andReturn('https://whisper.money/storage/card.png');
        $mock->shouldReceive('warm', 'forgetBefore', 'forget')->andReturnNull();
        $mock->shouldReceive('path')->andReturn('monthly-summaries/x/card.png');
    });

    expect((new MonthlySummaryEmail($summary->user, $summary))->render())
        ->toContain('Monthly savings goals')
        ->not->toContain('20.00')
        ->not->toContain('470.00');
});

test('the summary email keeps three tiles when the others fill them', function () {
    $summary = surfacesSummary();

    $labels = array_column(app(EmailPresenter::class)->present($summary->user, $summary, 'en', false)['kpis'], 'label');

    expect($labels)->toHaveCount(3)->not->toContain('Monthly savings goals');
});
