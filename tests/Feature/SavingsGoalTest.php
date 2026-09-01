<?php

use App\Enums\AccountType;
use App\Enums\LabelSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function onboardedSavingsUser(): User
{
    return User::factory()->create(['onboarded_at' => now()]);
}

/**
 * The account factory picks a random type, and the contribution sign depends on
 * it, so every test spells out the type it means.
 */
function savingsGoalAccount(User $user, AccountType $type = AccountType::Checking): Account
{
    return Account::factory()->create(['user_id' => $user->id, 'type' => $type]);
}

test('creating a goal creates a linked hidden label with the same name', function () {
    $user = onboardedSavingsUser();

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

    Label::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    SavingsGoal::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/settings/labels');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('labels', 1)
        ->where('labels.0.name', 'Groceries')
    );
});

test('saved amount is the negated net flow of transactions outside a savings account', function () {
    $user = onboardedSavingsUser();
    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = savingsGoalAccount($user);

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

test('saved amount counts a savings account inflow as the contribution', function () {
    $user = onboardedSavingsUser();
    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = savingsGoalAccount($user, AccountType::Savings);

    // On the savings account itself the arriving money (+500) IS the contribution,
    // and taking it back out (−200) subtracts.
    $deposit = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 50000,
    ]);
    $withdrawal = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -20000,
    ]);

    $deposit->labels()->attach($goal->label_id);
    $withdrawal->labels()->attach($goal->label_id);

    expect($goal->savedAmountInCents())->toBe(30000);
});

test('each leg of a transfer counts on its own terms', function () {
    $user = onboardedSavingsUser();
    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);

    // Both legs of the same 500 transfer: −500 leaving checking and +500
    // arriving in savings each read as +500. Tagging both therefore counts the
    // transfer twice — the sign is per transaction, and it is up to the user to
    // tag the leg they mean, exactly as it was before this rule existed.
    $checkingLeg = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => savingsGoalAccount($user)->id,
        'amount' => -50000,
    ]);
    $savingsLeg = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => savingsGoalAccount($user, AccountType::Savings)->id,
        'amount' => 50000,
    ]);

    $checkingLeg->labels()->attach($goal->label_id);
    $savingsLeg->labels()->attach($goal->label_id);

    expect($goal->savedAmountInCents())->toBe(100000);
});

test('the goals list applies the same sign rule per account type', function () {
    $user = onboardedSavingsUser();
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 400000,
        'initial_amount' => 0,
    ]);

    $checkingOutflow = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => savingsGoalAccount($user)->id,
        'amount' => -50000,
    ]);
    $savingsDeposit = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => savingsGoalAccount($user, AccountType::Savings)->id,
        'amount' => 30000,
    ]);
    $savingsWithdrawal = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => savingsGoalAccount($user, AccountType::Savings)->id,
        'amount' => -10000,
    ]);

    $checkingOutflow->labels()->attach($goal->label_id);
    $savingsDeposit->labels()->attach($goal->label_id);
    $savingsWithdrawal->labels()->attach($goal->label_id);

    // The grouped query behind the list is separate code from savedAmountInCents(),
    // so it gets its own assertion.
    $goals = SavingsGoal::withStatsForUser($user);

    expect($goals[0]['stats']['saved'])->toBe(70000)
        ->and($goal->savedAmountInCents())->toBe(70000);
});

test('show exposes computed progress stats', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 100000,
        'target_date' => null,
    ]);
    $account = savingsGoalAccount($user);
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

test('the goal page stats include the starting amount', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 400000,
        'initial_amount' => 100000,
        'target_date' => null,
    ]);
    $account = savingsGoalAccount($user);
    $contribution = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -25000,
        'transaction_date' => now()->subDays(10),
    ]);
    $contribution->labels()->attach($goal->label_id);

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page
            ->component('savings-goals/show')
            ->where('stats.saved', 125000)
            // The pace only counts the contribution, not the starting balance:
            // 25000 cents over the elapsed window, nowhere near 125000/day.
            ->where('stats.rate_per_day', fn ($rate) => $rate > 0 && $rate <= 2500.0)
        );
});

test('a goal can start from an amount already saved', function () {
    $user = onboardedSavingsUser();

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'House deposit',
        'target_amount' => 1000000,
        'initial_amount' => 300000,
    ])->assertRedirect();

    $goal = SavingsGoal::where('user_id', $user->id)->first();

    expect($goal->initial_amount)->toBe(300000)
        ->and($goal->savedAmountInCents())->toBe(300000);
});

test('the starting amount adds to the tagged transactions', function () {
    $user = onboardedSavingsUser();
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'initial_amount' => 100000,
    ]);
    $account = savingsGoalAccount($user);

    $contribution = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -25000,
    ]);
    $contribution->labels()->attach($goal->label_id);

    expect($goal->savedAmountInCents())->toBe(125000);
});

test('a goal with no starting amount defaults to zero', function () {
    $user = onboardedSavingsUser();

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Rainy day',
        'target_amount' => 500000,
    ])->assertRedirect();

    expect(SavingsGoal::where('user_id', $user->id)->first()->initial_amount)->toBe(0);
});

test('the starting amount can be adjusted later', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 800000,
        'initial_amount' => 100000,
    ]);

    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", [
        'initial_amount' => 250000,
    ])->assertRedirect();

    expect($goal->fresh()->savedAmountInCents())->toBe(250000);
});

test('the starting amount cannot be negative', function () {
    $user = onboardedSavingsUser();

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Negative',
        'target_amount' => 500000,
        'initial_amount' => -1,
    ])->assertSessionHasErrors('initial_amount');

    expect(SavingsGoal::where('user_id', $user->id)->count())->toBe(0);
});

test('the starting amount does not inflate the projected pace', function () {
    $start = Carbon::parse('2026-07-20');
    $today = Carbon::parse('2026-07-30');
    $targetDate = Carbon::parse('2026-12-31');

    // A goal opened ten days ago with 300k already put aside and nothing added since:
    // the pace is zero, not 30k a day.
    $stats = SavingsGoal::project(300000, 1000000, $start, $targetDate, $today, 300000);

    expect($stats['rate_per_day'])->toBe(0.0)
        ->and($stats['estimated_date'])->toBeNull()
        // The ideal pace runs from the starting balance to the target, so ten days in
        // it expects only a little more than what was already there.
        ->and($stats['expected_today'])->toBeGreaterThan(300000)
        ->and($stats['expected_today'])->toBeLessThan(400000)
        // Nothing added in those ten days, so the goal is behind its ideal pace
        // rather than being credited for money that was already there.
        ->and($stats['status'])->toBe('behind');
});

test('renaming the goal renames its label', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", [
        'name' => 'Emergency fund',
        'target_amount' => $goal->target_amount,
    ])->assertRedirect();

    $this->assertDatabaseHas('savings_goals', ['id' => $goal->id, 'name' => 'Emergency fund']);
    $this->assertDatabaseHas('labels', ['id' => $goal->label_id, 'name' => 'Emergency fund']);
});

test('the goals list adds the starting amount to its progress', function () {
    $user = onboardedSavingsUser();
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 400000,
        'initial_amount' => 100000,
    ]);
    $account = savingsGoalAccount($user);
    $contribution = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -100000,
    ]);
    $contribution->labels()->attach($goal->label_id);

    $goals = SavingsGoal::withStatsForUser($user);

    expect($goals[0]['initial_amount'])->toBe(100000)
        ->and($goals[0]['stats']['saved'])->toBe(200000)
        ->and($goals[0]['stats']['percentage'])->toBe(50.0);
});

test('deleting the goal also removes its label', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $labelId = $goal->label_id;

    $this->actingAs($user)->delete("/savings-goals/{$goal->id}")->assertRedirect('/budgets');

    $this->assertSoftDeleted('savings_goals', ['id' => $goal->id]);
    $this->assertSoftDeleted('labels', ['id' => $labelId]);
});

test('a goal name cannot collide with an existing label', function () {
    $user = onboardedSavingsUser();

    Label::factory()->create(['user_id' => $user->id, 'name' => 'Coche']);

    $response = $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Coche',
        'target_amount' => 500000,
    ]);

    $response->assertSessionHasErrors('name');
    expect(SavingsGoal::where('user_id', $user->id)->count())->toBe(0);
});

test('a user cannot view another users goal', function () {
    $owner = onboardedSavingsUser();
    $other = onboardedSavingsUser();

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

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = savingsGoalAccount($user);

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

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $stranger = User::factory()->create();
    $theirTransaction = Transaction::factory()->create([
        'user_id' => $stranger->id,
        'account_id' => savingsGoalAccount($stranger)->id,
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

    $goal = SavingsGoal::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($intruder)
        ->put("/savings-goals/{$goal->id}/transactions", ['transaction_ids' => []])
        ->assertForbidden();
});

test('the goal page only loads recent transactions when asked for them', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = savingsGoalAccount($user);
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

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = savingsGoalAccount($user);
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

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page->where('savingsGoal.label.source', LabelSource::SavingsGoal->value));
});

test('archiving a goal freezes its saved amount and removes its label', function () {
    Carbon::setTestNow('2026-03-10 09:00:00');
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 500000,
    ]);
    $account = savingsGoalAccount($user);
    $contribution = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -20000,
        'transaction_date' => now()->subDay(),
    ]);
    $contribution->labels()->attach($goal->label_id);

    // The named route, not back(): behind a proxy the previous-url redirect
    // resolves to the app's internal host and the dialog's request is blocked.
    $this->actingAs($user)
        ->post("/savings-goals/{$goal->id}/archive")
        ->assertRedirect(route('savings-goals.show', $goal));

    $goal->refresh();

    expect($goal->archived_at->toDateTimeString())->toBe('2026-03-10 09:00:00')
        ->and($goal->archived_saved_amount)->toBe(20000)
        ->and($goal->savedAmountInCents())->toBe(20000);

    // Soft-deleted, so it drops out of every picker through the global scope.
    expect(Label::find($goal->label_id))->toBeNull();
    $this->assertSoftDeleted('labels', ['id' => $goal->label_id]);
});

test('an archived goal does not move when a transaction is tagged afterwards', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $labelId = $goal->label_id;

    $this->actingAs($user)->post("/savings-goals/{$goal->id}/archive")->assertRedirect();

    // Straight through the pivot, which is the only way it could still happen.
    $late = Transaction::factory()->create(['user_id' => $user->id, 'amount' => -99999]);
    DB::table('label_transaction')->insert([
        'id' => (string) Str::uuid(),
        'label_id' => $labelId,
        'transaction_id' => $late->id,
    ]);

    expect($goal->fresh()->savedAmountInCents())->toBe(0);

    $goals = SavingsGoal::withStatsForUser($user->fresh());
    expect($goals[0]['stats']['saved'])->toBe(0);
});

test('an archived goal still lists the contributions it had', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
    $account = savingsGoalAccount($user);
    $contribution = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -15000,
        'transaction_date' => now()->subDays(2),
    ]);
    $contribution->labels()->attach($goal->label_id);

    $this->actingAs($user)->post("/savings-goals/{$goal->id}/archive")->assertRedirect();

    $this->actingAs($user)
        ->get("/savings-goals/{$goal->id}")
        ->assertOk()
        ->assertInertia(function ($page) use ($contribution) {
            $props = $page->toArray()['props'];

            expect(collect($props['transactions'])->pluck('id'))->toContain($contribution->id)
                ->and($props['stats']['saved'])->toBe(15000);
        });
});

test('an archived goal cannot be edited or take on more transactions', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->archived(12345)->create([
        'user_id' => $user->id,
        'name' => 'New car',
    ]);

    $this->actingAs($user)
        ->patch("/savings-goals/{$goal->id}", ['name' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($user)
        ->put("/savings-goals/{$goal->id}/transactions", ['transaction_ids' => []])
        ->assertForbidden();

    $this->actingAs($user)
        ->post("/savings-goals/{$goal->id}/archive")
        ->assertForbidden();

    expect($goal->fresh()->name)->toBe('New car');
});

test('an archived goal can still be deleted', function () {
    $user = onboardedSavingsUser();

    $goal = SavingsGoal::factory()->archived()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete("/savings-goals/{$goal->id}")
        ->assertRedirect('/budgets');

    $this->assertSoftDeleted('savings_goals', ['id' => $goal->id]);
});

test('users cannot archive goals they do not own', function () {
    $user = onboardedSavingsUser();
    $other = SavingsGoal::factory()->create();

    $this->actingAs($user)
        ->post("/savings-goals/{$other->id}/archive")
        ->assertForbidden();

    expect($other->fresh()->archived_at)->toBeNull();
});
