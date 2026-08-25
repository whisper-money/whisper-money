<?php

use App\Enums\LabelSource;
use App\Features\SavingsGoals;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

function onboardedSavingsUser(): User
{
    return User::factory()->create(['onboarded_at' => now()]);
}

test('creating a goal creates a linked hidden label with the same name', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $response = $this->actingAs($user)->post('/savings-goals', [
        'name' => 'New car',
        'target_amount' => 500000,
        'target_date' => now()->addYear()->toDateString(),
    ]);

    $response->assertRedirect();

    $goal = SavingsGoal::where('user_id', $user->id)->first();
    expect($goal)->not->toBeNull()
        ->and($goal->name)->toBe('New car')
        ->and($goal->target_amount)->toBe(500000);

    $this->assertDatabaseHas('labels', [
        'id' => $goal->label_id,
        'user_id' => $user->id,
        'name' => 'New car',
        'source' => LabelSource::SavingsGoal->value,
    ]);
});

test('goal-backed labels are hidden from the labels settings screen', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    Label::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    SavingsGoal::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/settings/labels');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('labels', 1)
        ->where('labels.0.name', 'Groceries')
    );
});

test('saved amount is the negated net flow of tagged transactions', function () {
    $user = onboardedSavingsUser();
    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    // Outflow to savings (−500) contributes +500; a withdrawal back (+200) subtracts.
    $outflow = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -50000,
    ]);
    $withdrawal = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 20000,
    ]);

    $outflow->labels()->attach($goal->label_id);
    $withdrawal->labels()->attach($goal->label_id);

    expect($goal->savedAmountInCents())->toBe(30000);
});

test('show exposes computed progress stats', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 100000,
        'target_date' => null,
    ]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -25000,
    ]);
    $transaction->labels()->attach($goal->label_id);

    $response = $this->actingAs($user)->get("/savings-goals/{$goal->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('savings-goals/show')
        ->where('stats.saved', 25000)
        ->where('stats.target', 100000)
    );
});

test('renaming the goal renames its label', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", [
        'name' => 'Emergency fund',
        'target_amount' => $goal->target_amount,
    ])->assertRedirect();

    $this->assertDatabaseHas('savings_goals', ['id' => $goal->id, 'name' => 'Emergency fund']);
    $this->assertDatabaseHas('labels', ['id' => $goal->label_id, 'name' => 'Emergency fund']);
});

test('deleting the goal also removes its label', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $labelId = $goal->label_id;

    $this->actingAs($user)->delete("/savings-goals/{$goal->id}")->assertRedirect('/budgets');

    $this->assertSoftDeleted('savings_goals', ['id' => $goal->id]);
    $this->assertSoftDeleted('labels', ['id' => $labelId]);
});

test('a goal name cannot collide with an existing label', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    Label::factory()->create(['user_id' => $user->id, 'name' => 'Coche']);

    $response = $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Coche',
        'target_amount' => 500000,
    ]);

    $response->assertSessionHasErrors('name');
    expect(SavingsGoal::where('user_id', $user->id)->count())->toBe(0);
});

test('routes are gated behind the feature flag', function () {
    $user = onboardedSavingsUser();

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Blocked',
        'target_amount' => 100000,
    ])->assertNotFound();
});

test('a user cannot view another users goal', function () {
    $owner = onboardedSavingsUser();
    $other = onboardedSavingsUser();
    Feature::for($other)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)->get("/savings-goals/{$goal->id}")->assertForbidden();
});

test('projection reports behind schedule and a required monthly pace', function () {
    $start = Carbon::parse('2026-06-20');
    $today = Carbon::parse('2026-07-20');
    $targetDate = Carbon::parse('2026-09-18');

    $stats = SavingsGoal::project(100000, 500000, $start, $targetDate, $today);

    expect($stats['status'])->toBe('behind')
        ->and($stats['percentage'])->toBe(20.0)
        ->and($stats['required_per_month'])->toBe(200000)
        ->and($stats['estimated_date'])->not->toBeNull();
});

test('projection reports a completed goal', function () {
    $start = Carbon::parse('2026-06-20');
    $today = Carbon::parse('2026-07-20');
    $targetDate = Carbon::parse('2026-09-18');

    $stats = SavingsGoal::project(520000, 500000, $start, $targetDate, $today);

    expect($stats['status'])->toBe('completed')
        ->and($stats['required_per_month'])->toBe(0);
});

test('effective start anchors on the earliest contribution predating creation', function () {
    $created = Carbon::parse('2026-07-20');

    // A goal created today whose money was set aside two years ago.
    $start = SavingsGoal::effectiveStart($created, '2024-07-20');
    expect($start->toDateString())->toBe('2024-07-20');

    // The real ~2-year window keeps the rate sane instead of projecting completion
    // tomorrow (which is what anchoring on created_at would produce).
    $stats = SavingsGoal::project(250000, 500000, $start, null, $created);
    expect($stats['rate_per_day'])->toBeLessThan(1000.0);
});

test('effective start keeps creation date when no earlier contribution exists', function () {
    $created = Carbon::parse('2026-07-20');

    expect(SavingsGoal::effectiveStart($created, null)->toDateString())->toBe('2026-07-20')
        ->and(SavingsGoal::effectiveStart($created, '2026-08-01')->toDateString())->toBe('2026-07-20');
});

test('syncing transactions attaches the ticked ones and detaches the rest', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $alreadyTagged = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -50000,
    ]);
    $newlyTicked = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -20000,
    ]);
    $alreadyTagged->labels()->attach($goal->label_id);

    $response = $this->actingAs($user)->put("/savings-goals/{$goal->id}/transactions", [
        'transaction_ids' => [$newlyTicked->id],
    ]);

    $response->assertRedirect();

    expect($goal->fresh()->savedAmountInCents())->toBe(20000);
    $this->assertDatabaseMissing('label_transaction', [
        'label_id' => $goal->label_id,
        'transaction_id' => $alreadyTagged->id,
    ]);
});

test('syncing transactions ignores ids belonging to someone else', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $stranger = User::factory()->create();
    $theirTransaction = Transaction::factory()->create([
        'user_id' => $stranger->id,
        'account_id' => Account::factory()->create(['user_id' => $stranger->id])->id,
        'amount' => -50000,
    ]);

    $this->actingAs($user)->put("/savings-goals/{$goal->id}/transactions", [
        'transaction_ids' => [$theirTransaction->id],
    ])->assertRedirect();

    $this->assertDatabaseMissing('label_transaction', [
        'label_id' => $goal->label_id,
        'transaction_id' => $theirTransaction->id,
    ]);
});

test('another user cannot sync transactions on a goal that is not theirs', function () {
    $owner = onboardedSavingsUser();
    $intruder = onboardedSavingsUser();
    Feature::for($intruder)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($intruder)
        ->put("/savings-goals/{$goal->id}/transactions", ['transaction_ids' => []])
        ->assertForbidden();
});

test('the goal page only loads recent transactions when asked for them', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -10000,
    ]);

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page->missing('recentTransactions'));

    $this->actingAs($user)->withoutVite()
        ->get("/savings-goals/{$goal->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'savings-goals/show',
            'X-Inertia-Partial-Data' => 'recentTransactions',
        ])
        ->assertOk()
        ->assertJsonCount(1, 'props.recentTransactions');
});

test('the link dialog can widen the recent transactions window, up to a cap', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    // One shared category: the factory only has a handful of unique names to give.
    $category = Category::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->count(55)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -1000,
    ]);

    $recentTransactions = function (int|string|null $recent) use ($user, $goal) {
        $url = "/savings-goals/{$goal->id}".($recent === null ? '' : "?recent={$recent}");

        return $this->actingAs($user)->withoutVite()
            ->get($url, [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'savings-goals/show',
                'X-Inertia-Partial-Data' => 'recentTransactions',
            ])
            ->assertOk()
            ->json('props.recentTransactions');
    };

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page->where('recentPageSize', 50));

    expect($recentTransactions(null))->toHaveCount(50);
    expect($recentTransactions(100))->toHaveCount(55);
    // Garbage falls back to the default page rather than to an unbounded query.
    expect($recentTransactions('nonsense'))->toHaveCount(50);

    // Asking past the cap is clamped to it, which only the query itself can show
    // without seeding hundreds of rows.
    DB::enableQueryLog();
    $recentTransactions(10000);

    $clamped = collect(DB::getQueryLog())
        ->contains(fn (array $entry) => str_contains($entry['query'], 'limit 500'));

    expect($clamped)->toBeTrue();
});

test('the goal label carries its source so the UI can mark it as a savings goal', function () {
    $user = onboardedSavingsUser();
    Feature::for($user)->activate(SavingsGoals::class);

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page->where('savingsGoal.label.source', LabelSource::SavingsGoal->value));
});
