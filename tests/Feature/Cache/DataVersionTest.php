<?php

use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\Label;
use App\Models\LoanDetail;
use App\Models\RealEstateDetail;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\AutomationRuleService;
use App\Services\Cache\DataVersion;
use App\Services\CategoryTree;
use App\Services\CurrencyConversionService;
use App\Services\ExchangeRateService;
use App\Services\Imports\AccountPurger;
use App\Services\LoanBalanceGeneratorService;
use Carbon\Carbon;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Asserts the callback changes the user's token, read before and after it.
 */
function expectBump(User|string $user, Closure $write): void
{
    $id = $user instanceof User ? $user->id : $user;
    $before = DataVersion::current($id);

    $write();

    expect(DataVersion::current($id))->not->toBe($before);
}

/**
 * How many times the callback writes the user's token key.
 */
function tokenWrites(User $user, Closure $callback): int
{
    $writes = 0;
    Event::listen(KeyWritten::class, function (KeyWritten $event) use ($user, &$writes): void {
        if ($event->key === "wm:dv:{$user->id}") {
            $writes++;
        }
    });

    $callback();

    return $writes;
}

/**
 * A row of the given model owned by the user, directly or through its parent.
 *
 * @param  class-string<Model>  $model
 */
function ownedRow(string $model, User $user): Model
{
    $owned = ['user_id' => $user->id];

    return match ($model) {
        AccountBalance::class, CreditCardDetail::class, LoanDetail::class, RealEstateDetail::class => $model::factory()->create(['account_id' => Account::factory()->create($owned)->id]),
        BudgetPeriod::class => BudgetPeriod::factory()->create(['budget_id' => Budget::factory()->create($owned)->id]),
        BudgetTransaction::class => BudgetTransaction::factory()->create(['transaction_id' => Transaction::factory()->create($owned)->id]),
        SavingsGoalPeriod::class => SavingsGoalPeriod::factory()->create(['savings_goal_id' => SavingsGoal::factory()->create($owned)->id]),
        default => $model::factory()->create($owned),
    };
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('keeps a stable token until something is bumped', function () {
    $token = DataVersion::current($this->user->id);

    expect(DataVersion::current($this->user->id))->toBe($token)
        ->and(Str::isUlid($token))->toBeTrue();
});

it('mints a fresh token instead of reusing an old value when the key was evicted', function () {
    $token = DataVersion::current($this->user->id);

    cache()->forget("wm:dv:{$this->user->id}");

    expect(DataVersion::current($this->user->id))->not->toBe($token);
});

it('bumps the owner when a row is created', function () {
    expectBump($this->user, fn () => Transaction::factory()->create(['user_id' => $this->user->id]));
});

it('bumps the owner when a row is updated and when it is deleted', function (string $model) {
    $row = ownedRow($model, $this->user);

    expectBump($this->user, fn () => $row->forceFill(['updated_at' => now()->addMinute()])->save());
    expectBump($this->user, fn () => $row->delete());
})->with([
    Transaction::class,
    Account::class,
    AccountBalance::class,
    Category::class,
    Label::class,
    Budget::class,
    BudgetPeriod::class,
    BudgetTransaction::class,
    SavingsGoal::class,
    SavingsGoalPeriod::class,
    UserSetting::class,
    CreditCardDetail::class,
    LoanDetail::class,
    RealEstateDetail::class,
]);

it('bumps the owner on restore and force delete', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $category->delete();

    expectBump($this->user, fn () => $category->restore());
    expectBump($this->user, fn () => $category->forceDelete());
});

it('bumps the owner when transaction labels are attached, synced and detached', function () {
    $transaction = Transaction::factory()->create(['user_id' => $this->user->id]);
    [$groceries, $trip] = Label::factory()->count(2)->create(['user_id' => $this->user->id]);

    expectBump($this->user, fn () => $transaction->labels()->attach($groceries->id));
    expectBump($this->user, fn () => $transaction->labels()->sync([$trip->id]));
    expectBump($this->user, fn () => $transaction->labels()->detach($trip->id));
});

it('bumps the owner after a quiet save', function () {
    $transaction = Transaction::factory()->create(['user_id' => $this->user->id]);
    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'action_category_id' => null,
        'action_note' => 'Reviewed',
    ]);

    expectBump($this->user, fn () => app(AutomationRuleService::class)
        ->applyRuleActionsToTransactions(Transaction::query()->whereKey($transaction->id)->get(), $rule));

    expect($transaction->fresh()->notes)->toContain('Reviewed');
});

it('bumps the owner after a builder mass update', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'category_id' => $category->id]);

    expectBump($this->user, fn () => app(CategoryTree::class)->deleteSubtree($category));
});

it('bumps the owner after a balance upsert', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id]);

    expectBump($this->user, fn () => app(LoanBalanceGeneratorService::class)->generateHistoricalBalances(
        $account,
        originalAmount: 100_000,
        startDate: Carbon::today()->subMonths(3),
        currentBalance: 90_000,
    ));
});

it('bumps the owner after a hard delete that fires no model events', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $account->id]);

    expectBump($this->user, fn () => app(AccountPurger::class)->purge(collect([$account->id])));
});

it('bumps the global rates token when exchange rates are stored', function () {
    $this->mock(CurrencyConversionService::class)
        ->shouldReceive('getRatesForCurrency')
        ->andReturn(['usd' => 1.1]);

    expectBump(DataVersion::RATES, fn () => app(ExchangeRateService::class)->getRates('EUR', '2026-01-15'));
});

it('bumps only after the transaction commits', function () {
    $before = DataVersion::current($this->user->id);

    DB::transaction(function () use ($before): void {
        Transaction::factory()->create(['user_id' => $this->user->id]);

        expect(DataVersion::current($this->user->id))->toBe($before);
    });

    expect(DataVersion::current($this->user->id))->not->toBe($before);
});

it('does not bump when the transaction rolls back', function () {
    $before = DataVersion::current($this->user->id);

    DB::beginTransaction();
    Transaction::factory()->create(['user_id' => $this->user->id]);
    DB::rollBack();

    expect(DataVersion::current($this->user->id))->toBe($before);
});

it('bumps every member of a shared space, and nobody else for a personal one', function () {
    $partner = User::factory()->create();
    $household = Space::factory()->create(['owner_id' => $this->user->id]);
    $household->members()->attach($partner->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $stranger = User::factory()->create();
    $strangerToken = DataVersion::current($stranger->id);

    expectBump($partner, fn () => Transaction::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $household->id,
    ]));

    $partnerToken = DataVersion::current($partner->id);
    Transaction::factory()->create(['user_id' => $this->user->id, 'space_id' => $this->user->activeSpace()->id]);

    expect(DataVersion::current($partner->id))->toBe($partnerToken)
        ->and(DataVersion::current($stranger->id))->toBe($strangerToken);
});

it('collects bumps while deferred and flushes them once at the end', function () {
    $before = DataVersion::current($this->user->id);

    $writes = tokenWrites($this->user, fn () => DataVersion::deferBumps(function () use ($before): void {
        Transaction::factory()->count(3)->create(['user_id' => $this->user->id]);
        DataVersion::bumpUser($this->user);

        expect(DataVersion::current($this->user->id))->toBe($before);
    }));

    expect($writes)->toBe(1)
        ->and(DataVersion::current($this->user->id))->not->toBe($before);
});

it('still flushes deferred bumps when the batch throws', function () {
    $before = DataVersion::current($this->user->id);

    expect(fn () => DataVersion::deferBumps(function (): void {
        Transaction::factory()->create(['user_id' => $this->user->id]);

        throw new RuntimeException('Bank went away');
    }))->toThrow(RuntimeException::class);

    expect(DataVersion::current($this->user->id))->not->toBe($before);
});

it('writes the token once for a batch job that touches many rows', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $budget = Budget::factory()->forCategories($category)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);
    $transactions = Transaction::factory()->count(4)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDay(),
        'amount' => -1000,
    ]);
    BudgetTransaction::query()->delete();

    $writes = tokenWrites($this->user, fn () => ReassignTransactionsToBudgets::dispatch($transactions->modelKeys(), notify: false));

    expect(BudgetTransaction::query()->count())->toBe(4)
        ->and($writes)->toBe(1);
});

it('bumps the previous owner too when a row moves to another space', function () {
    $partner = User::factory()->create();
    $household = Space::factory()->create(['owner_id' => $partner->id]);
    $account = Account::factory()->create(['user_id' => $partner->id, 'space_id' => $household->id]);

    expectBump($partner, fn () => $account->update(['space_id' => $partner->activeSpace()->id]));
});

it('writes the token once for a scheduled sweep', function () {
    Budget::factory()->count(3)->create(['user_id' => $this->user->id]);

    expect(tokenWrites($this->user, fn () => $this->artisan('budgets:generate-periods')->assertSuccessful()))->toBe(1);
});
