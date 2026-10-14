<?php

use App\Enums\SavingsGoalKind;
use App\Features\MonthlySavingsGoals;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateSavingsGoal;
use App\Mcp\Tools\ListSavingsGoals;
use App\Mcp\Tools\UpdateSavingsGoal;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * Creating a monthly goal sits behind the MonthlySavingsGoals flag, and only
 * creating one: a monthly goal that already exists keeps working without it.
 */
function flaggedGoalUser(bool $canCreateMonthly): User
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);

    if ($canCreateMonthly) {
        Feature::for($user)->activate(MonthlySavingsGoals::class);
    }

    return $user;
}

/**
 * @param  array<string, mixed>  $arguments
 */
function callFlaggedGoalTool(User $user, string $tool, array $arguments = []): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-10 12:00:00')));

test('the flag is off by default', function () {
    expect(User::factory()->create()->canCreateMonthlySavingsGoals())->toBeFalse();
});

test('without the flag the web refuses to create a monthly goal', function () {
    $user = flaggedGoalUser(canCreateMonthly: false);

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 30000,
    ])->assertForbidden();

    expect(SavingsGoal::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('without the flag a one-off goal is still created', function () {
    $user = flaggedGoalUser(canCreateMonthly: false);

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'New car',
        'target_amount' => 500000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(SavingsGoal::query()->where('user_id', $user->id)->sole()->kind)->toBe(SavingsGoalKind::OneOff);
});

test('with the flag the web creates a monthly goal', function () {
    $user = flaggedGoalUser(canCreateMonthly: true);

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 30000,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(SavingsGoal::query()->where('user_id', $user->id)->sole()->kind)->toBe(SavingsGoalKind::Monthly);
});

test('the client learns whether the monthly kind can be offered', function (bool $canCreateMonthly) {
    $this->actingAs(flaggedGoalUser($canCreateMonthly))->get('/budgets')
        ->assertInertia(fn ($page) => $page->where('features.monthlySavingsGoals', $canCreateMonthly));
})->with([true, false]);

test('without the flag an existing monthly goal keeps working on the web', function () {
    $user = flaggedGoalUser(canCreateMonthly: false);
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page->has('monthlySavingsGoals', 1));
    $this->actingAs($user)->get("/savings-goals/{$goal->id}")->assertOk();

    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", ['monthly_target_type' => 'amount', 'monthly_target_amount' => 45000])
        ->assertSessionHasNoErrors();
    $this->actingAs($user)->patch("/settings/notifications/savings-goals/{$goal->id}", ['notify_on_month_end_reminder' => false])
        ->assertSessionHasNoErrors();

    $this->artisan('savings-goals:generate-periods')->assertSuccessful();

    $goal->refresh();
    expect($goal->monthly_target_amount)->toBe(45000)
        ->and($goal->notify_on_month_end_reminder)->toBeFalse()
        ->and($goal->periods()->count())->toBe(1);

    $this->actingAs($user)->post("/savings-goals/{$goal->id}/archive")->assertSessionHasNoErrors();

    expect($goal->refresh()->archived_at)->not->toBeNull();
});

test('without the flag create_savings_goal refuses a monthly goal and says what to do instead', function () {
    $user = flaggedGoalUser(canCreateMonthly: false);

    callFlaggedGoalTool($user, CreateSavingsGoal::class, [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 30000,
    ])->assertHasErrors(['Monthly savings goals are not available on this account yet, so none can be created. Offer a one_off goal instead']);

    expect(SavingsGoal::query()->where('user_id', $user->id)->exists())->toBeFalse();

    callFlaggedGoalTool($user, CreateSavingsGoal::class, [
        'name' => 'New car',
        'kind' => 'one_off',
        'target_amount' => 500000,
    ])->assertOk()->assertHasNoErrors();
});

test('with the flag create_savings_goal creates a monthly goal', function () {
    $user = flaggedGoalUser(canCreateMonthly: true);

    callFlaggedGoalTool($user, CreateSavingsGoal::class, [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 30000,
    ])->assertOk()->assertHasNoErrors();

    expect(SavingsGoal::query()->where('user_id', $user->id)->sole()->kind)->toBe(SavingsGoalKind::Monthly);
});

test('without the flag an existing monthly goal is still listed and updated through MCP', function () {
    $user = flaggedGoalUser(canCreateMonthly: false);
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id, 'name' => 'Emergency fund']);

    callFlaggedGoalTool($user, ListSavingsGoals::class)
        ->assertOk()->assertSee($goal->id)->assertSee('"kind":"monthly"');

    callFlaggedGoalTool($user, UpdateSavingsGoal::class, [
        'savings_goal_id' => $goal->id,
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 45000,
    ])->assertOk()->assertHasNoErrors();

    expect($goal->refresh()->monthly_target_amount)->toBe(45000);
});
