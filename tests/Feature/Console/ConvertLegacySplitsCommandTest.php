<?php

use App\Console\Commands\AuditTransactionSplitsCommand;
use App\Enums\BudgetPeriodType;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\RecurringCadence;
use App\Enums\TransactionSource;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\ExchangeRate;
use App\Models\Import;
use App\Models\Label;
use App\Models\RecurringSeries;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\Budgets\SplitBudgetRows;
use App\Services\BudgetTransactionService;
use App\Services\CashflowAnalyticsService;
use App\Services\CreditCards\CreditCardStatementService;
use App\Services\PeriodComparator;
use App\Services\Transactions\LegacySplits\LegacySplitConverter;
use App\Services\TransactionSplitter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

beforeEach(function () {
    Http::fake();
    // Mid-March: March's budget periods are running, February's are closed.
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
});

/**
 * A live transaction split the old way: one line per [category, amount].
 *
 * @param  list<array{0: Category, 1: int}>  $lines
 * @param  list<Label>  $labels
 * @param  array<string, mixed>  $attributes
 */
function legacySplitOn(Account $account, int $amount, array $lines, array $labels = [], array $attributes = []): Transaction
{
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $account->user_id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => $amount,
        'currency_code' => $account->currency_code,
        'transaction_date' => '2026-03-10',
        ...$attributes,
    ]);
    $transaction->labels()->attach(collect($labels)->pluck('id'));

    return fileUnderLegacyLines($transaction, array_map(fn (array $line): array => ['category_id' => $line[0]->id, 'amount' => $line[1]], $lines));
}

/**
 * @param  list<Category>  $categories
 * @param  list<Label>  $labels
 */
function conversionBudget(User $owner, array $categories = [], array $labels = [], bool $catchAll = false, ?Space $space = null): Budget
{
    $budget = Budget::factory()->create([
        'user_id' => $owner->id,
        'space_id' => ($space ?? $owner->personalSpace)->id,
        'is_catch_all' => $catchAll,
    ]);
    $budget->categories()->sync(collect($categories)->pluck('id'));
    $budget->labels()->sync(collect($labels)->pluck('id'));

    return $budget;
}

function conversionPeriod(Budget $budget, string $start, string $end, ?int $regular = null): BudgetPeriod
{
    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => $start,
        'end_date' => $end,
        'allocated_amount' => 50000,
        'regular_allocated_amount' => $regular,
    ]);
}

/**
 * Two users sharing a space, every kind of account, budget and row the
 * conversion has to respect, and legacy splits on all of them, plus one row
 * for each reason a split is left alone.
 *
 * @return array<string, mixed>
 */
function legacySplitHousehold(): array
{
    $owner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $partner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $personal = $owner->personalSpace;
    $household = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $household->members()->attach($partner->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    foreach (['2026-02-10', '2026-03-04'] as $date) {
        ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => $date, 'rates' => ['usd' => 1.0837]]);
    }

    $account = fn (User $user, Space $space, string $currency = 'EUR', int $share = 100): Account => Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'currency_code' => $currency,
        'ownership_percentage' => $share,
    ]);
    $checking = $account($owner, $personal);
    $half = $account($owner, $personal, share: 50);
    $third = $account($owner, $personal, share: 33);
    $dollars = $account($owner, $personal, 'USD');
    $joint = $account($owner, $household);
    $partnerChecking = $account($partner, $household);

    $category = fn (User $user, Space $space, CategoryType $type = CategoryType::Expense): Category => Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'type' => $type,
    ]);
    $groceries = $category($owner, $personal);
    $dining = $category($owner, $personal);
    $fun = $category($owner, $personal);
    $misc = $category($owner, $personal);
    $jointFood = $category($owner, $household);
    $partnerFood = $category($partner, $household);

    $holiday = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $personal->id]);
    $untracked = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $personal->id]);

    $import = Import::factory()->create(['user_id' => $owner->id]);
    $splits = [
        'labelled' => legacySplitOn($checking, -5000, [[$groceries, -3000], [$dining, -2000]], [$holiday, $untracked], ['description' => 'MERCADONA S.A.']),
        'half owned' => legacySplitOn($half, -1001, [[$groceries, -501], [$fun, -500]], attributes: ['transaction_date' => '2026-03-11']),
        'third owned' => legacySplitOn($third, -1003, [[$dining, -502], [$fun, -501]], [$holiday], ['transaction_date' => '2026-03-12']),
        // Each line converts to -3076; the parent to -9227, so the last line takes -3075.
        'dollars' => legacySplitOn($dollars, -9999, [[$groceries, -3333], [$dining, -3333], [$fun, -3333]], attributes: ['transaction_date' => '2026-03-04']),
        'imported' => legacySplitOn($checking, -2000, [[$groceries, -1500], [$fun, -500]], attributes: ['transaction_date' => '2026-03-05', 'source' => TransactionSource::Imported, 'import_id' => $import->id]),
        'recurring' => legacySplitOn($checking, -1500, [[$fun, -1000], [$misc, -500]], attributes: ['transaction_date' => '2026-03-06']),
        'encrypted' => legacySplitOn($checking, -800, [[$groceries, -400], [$dining, -400]], attributes: ['description' => 'c2VjcmV0', 'description_iv' => 'aXZpdml2aXZpdml2', 'notes' => 'bm90ZXM=', 'notes_iv' => 'aXZpdml2aXZpdml3']),
        'closed month' => legacySplitOn($checking, -3000, [[$groceries, -2000], [$fun, -1000]], attributes: ['transaction_date' => '2026-02-10']),
        'joint' => legacySplitOn($joint, -4000, [[$jointFood, -2500], [$jointFood, -1500]], attributes: ['transaction_date' => '2026-03-09']),
        'partner' => legacySplitOn($partnerChecking, -6000, [[$partnerFood, -4000], [$partnerFood, -2000]], attributes: ['transaction_date' => '2026-03-08']),
    ];

    $series = RecurringSeries::factory()->cadence(RecurringCadence::Monthly)->create([
        'user_id' => $owner->id,
        'space_id' => $personal->id,
        'direction' => 'expense',
        'expected_amount' => -1500,
        'currency_code' => 'EUR',
    ]);
    $series->transactions()->attach($splits['recurring']->id);

    $skipped = [
        'trashed' => legacySplitOn($checking, -700, [[$groceries, -400], [$fun, -300]]),
        'unbalanced' => legacySplitOn($checking, -1000, [[$groceries, -600], [$fun, -300]]),
        'one line' => legacySplitOn($checking, -500, [[$groceries, -500]]),
    ];
    $skipped['trashed']->deleteQuietly();
    $parts = app(TransactionSplitter::class)->split(
        Transaction::factory()->plaintext()->create(['user_id' => $owner->id, 'account_id' => $checking->id, 'category_id' => null, 'amount' => -900, 'transaction_date' => '2026-03-03']),
        [['amount' => -600], ['amount' => -300]],
    );
    TransactionSplit::factory()->create(['transaction_id' => $parts->first()->id, 'category_id' => $groceries->id, 'amount' => -400, 'position' => 0]);
    TransactionSplit::factory()->create(['transaction_id' => $parts->first()->id, 'category_id' => $fun->id, 'amount' => -200, 'position' => 1]);
    $skipped['part'] = $parts->first();

    $food = conversionBudget($owner, [$groceries, $dining]);
    $foodFebruary = conversionPeriod($food, '2026-02-01', '2026-02-28');
    // A one-off month: its allocation differs from the regular one.
    $foodMarch = conversionPeriod($food, '2026-03-01', '2026-03-31', regular: 30000);
    $holidays = conversionBudget($owner, labels: [$holiday]);
    $holidaysMarch = conversionPeriod($holidays, '2026-03-01', '2026-03-31');
    $funBudget = conversionBudget($owner, [$fun]);
    $funMarch = conversionPeriod($funBudget, '2026-03-01', '2026-03-31');
    $catchAll = conversionBudget($owner, catchAll: true);
    $catchAllMarch = conversionPeriod($catchAll, '2026-03-01', '2026-03-31');
    $partnerBudget = conversionBudget($partner, [$partnerFood], space: $household);
    $partnerMarch = conversionPeriod($partnerBudget, '2026-03-01', '2026-03-31');

    foreach ([$foodFebruary, $foodMarch, $holidaysMarch, $funMarch, $partnerMarch, $catchAllMarch] as $period) {
        app(BudgetTransactionService::class)->assignHistoricalTransactionsToPeriod($period);
    }

    // Archived after it counted March: it takes nothing new, keeps what it has.
    $funBudget->update(['archived_at' => now()]);
    // Written under tracking the budget no longer has: today it would hold 2000.
    BudgetTransaction::query()
        ->where('budget_period_id', $foodFebruary->id)
        ->where('transaction_id', $splits['closed month']->id)
        ->update(['amount' => 2500]);

    return [
        'owner' => $owner,
        'partner' => $partner,
        'splits' => $splits,
        'skipped' => $skipped,
        'series' => $series,
        'periods' => compact('foodFebruary', 'foodMarch', 'holidaysMarch', 'funMarch', 'catchAllMarch', 'partnerMarch'),
    ];
}

/**
 * The tables a conversion writes, row by row.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function conversionTables(bool $withUpdatedAt = true): array
{
    $rows = fn (string $table, array $order, array $columns = ['*'], array $except = []): array => DB::table($table)
        ->orderBy($order[0])->orderBy($order[1])
        ->get($columns)
        ->map(fn (object $row): array => Arr::except((array) $row, $except))
        ->all();

    return [
        'transactions' => $rows('transactions', ['id', 'id'], except: $withUpdatedAt ? [] : ['updated_at']),
        'budget_transactions' => $rows('budget_transactions', ['transaction_id', 'budget_period_id'], ['transaction_id', 'budget_period_id', 'amount']),
        'label_transaction' => $rows('label_transaction', ['transaction_id', 'label_id'], ['label_id', 'transaction_id']),
        'transaction_splits' => $rows('transaction_splits', ['transaction_id', 'position']),
    ];
}

/**
 * Bind a converter that, after converting an original, runs $sabotage on it
 * until $sabotage says it did something: a conversion that gets the figures
 * wrong in one way, for the verification to catch.
 *
 * @param  Closure(Transaction): bool  $sabotage
 */
function sabotageConversion(Closure $sabotage): void
{
    app()->bind(LegacySplitConverter::class, fn ($app) => new class($app->make(SplitBudgetRows::class), $app->make(AuditTransactionSplitsCommand::class), $sabotage) extends LegacySplitConverter
    {
        private bool $sabotaged = false;

        public function __construct(SplitBudgetRows $budgetRows, AuditTransactionSplitsCommand $audit, private readonly Closure $sabotage)
        {
            parent::__construct($budgetRows, $audit);
        }

        public function convert(Transaction $original): array
        {
            $converted = parent::convert($original);
            $this->sabotaged = $this->sabotaged || ($this->sabotage)($original);

            return $converted;
        }
    });
}

/**
 * The ids of an original's live parts.
 *
 * @return list<string>
 */
function partIdsOf(Transaction $original): array
{
    return Transaction::query()->where('split_parent_id', $original->id)->orderBy('id')->pluck('id')->all();
}

/**
 * What every budget period has spent, by period id.
 *
 * @return array<string, int>
 */
function spentByPeriod(): array
{
    return BudgetPeriod::query()->withSum('budgetTransactions', 'amount')->get()
        ->mapWithKeys(fn (BudgetPeriod $period): array => [$period->id => (int) $period->budget_transactions_sum_amount])
        ->all();
}

it('converts a legacy split into one part per line, carrying what the original carries', function () {
    $owner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $owner->id, 'currency_code' => 'EUR']);
    $groceries = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    $dining = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    $label = Label::factory()->create(['user_id' => $owner->id]);
    $gone = Label::factory()->create(['user_id' => $owner->id]);
    $import = Import::factory()->create(['user_id' => $owner->id]);
    $original = legacySplitOn($account, -5000, [[$groceries, -3000], [$dining, -2000]], [$label, $gone], [
        'source' => TransactionSource::EnableBanking,
        'description' => 'MERCADONA S.A.',
        'original_description' => 'COMPRA MERCADONA',
        'notes' => 'weekly shop',
        'transaction_date' => '2026-03-10',
        'source_date' => '2026-03-08',
        'creditor_name' => 'Mercadona',
        'debtor_name' => 'Me',
        'import_id' => $import->id,
        'external_transaction_id' => 'bank-ref-1',
        'dedup_fingerprint' => 'fp-of-the-original',
        'raw_data' => ['booking' => 'raw'],
        'created_at' => '2026-03-10 09:00:00',
    ]);
    $gone->delete();
    $series = RecurringSeries::factory()->cadence(RecurringCadence::Monthly)->create(['user_id' => $owner->id, 'space_id' => $original->space_id]);
    $series->transactions()->attach($original->id);
    $original = $original->fresh();

    Event::fake();
    Queue::fake();
    Mail::fake();
    Notification::fake();
    $this->travel(1)->minutes();

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])->assertSuccessful();

    $parts = Transaction::query()->where('split_parent_id', $original->id)->orderBy('amount')->get();

    expect($parts->map(fn (Transaction $part): array => [$part->amount, $part->category_id])->all())
        ->toBe([[-3000, $groceries->id], [-2000, $dining->id]]);

    foreach ($parts as $part) {
        expect($part->only(['user_id', 'space_id', 'account_id', 'currency_code', 'description', 'description_iv', 'original_description', 'notes', 'notes_iv', 'source', 'creditor_name', 'debtor_name', 'import_id']))
            ->toBe($original->only(['user_id', 'space_id', 'account_id', 'currency_code', 'description', 'description_iv', 'original_description', 'notes', 'notes_iv', 'source', 'creditor_name', 'debtor_name', 'import_id']))
            ->and($part->transaction_date->toDateString())->toBe('2026-03-10')
            ->and($part->source_date->toDateString())->toBe('2026-03-08')
            ->and($part->category_source)->toBe(CategorySource::Manual)
            ->and([$part->categorized_by_rule_id, $part->ai_confidence, $part->ai_suggested_category_id, $part->ai_model])->toBe([null, null, null, null])
            // The bank's ids stay on the original: a part is our row.
            ->and([$part->external_transaction_id, $part->dedup_fingerprint, $part->raw_data])->toBe([null, null, null])
            // Not new money: the synced email counts rows by created_at.
            ->and($part->created_at->toDateTimeString())->toBe('2026-03-10 09:00:00')
            // New to the offline sync, which reads updated_at.
            ->and($part->updated_at->gt($original->updated_at))->toBeTrue()
            ->and(DB::table('label_transaction')->where('transaction_id', $part->id)->pluck('label_id')->sort()->values()->all())
            ->toBe(collect([$label->id, $gone->id])->sort()->values()->all());
    }

    $trashed = Transaction::withTrashed()->findOrFail($original->id);

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->updated_at->gt($original->updated_at))->toBeTrue()
        ->and($trashed->splits()->count())->toBe(2)
        ->and($trashed->dedup_fingerprint)->toBe('fp-of-the-original')
        ->and($trashed->external_transaction_id)->toBe('bank-ref-1')
        ->and(DB::table('label_transaction')->where('transaction_id', $original->id)->count())->toBe(2)
        // The series still reads the movement, as its original.
        ->and($series->transactions()->pluck('transactions.id')->all())->toBe([$original->id]);

    // Quiet: no rule, AI, budget job, email or notification ran.
    Event::assertNotDispatched(TransactionCreated::class);
    Event::assertNotDispatched(TransactionUpdated::class);
    Event::assertNotDispatched(TransactionDeleted::class);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('keeps the bank fingerprint on the original, so a sync does not bring a converted movement back', function () {
    $owner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $owner->id, 'currency_code' => 'EUR']);
    $groceries = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    $original = legacySplitOn($account, -5340, [[$groceries, -3490], [$groceries, -1850]], attributes: [
        'source' => TransactionSource::EnableBanking,
        'dedup_fingerprint' => 'fingerprint-of-the-original',
        'external_transaction_id' => 'bank-ref-1',
    ]);

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])->assertSuccessful();

    // What the sync reads to decide "already imported": it reads trashed rows.
    $known = $account->transactions()->withTrashed()->pluck('dedup_fingerprint')->filter()->values()->all();

    expect($known)->toBe(['fingerprint-of-the-original'])
        ->and(Transaction::withTrashed()->where('external_transaction_id', 'bank-ref-1')->pluck('id')->all())->toBe([$original->id])
        ->and($account->transactions()->pluck('amount')->sort()->values()->all())->toBe([-3490, -1850]);
});

it('leaves a credit card\'s statement figures as they were', function () {
    $owner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $card = Account::factory()->creditCard()->create(['user_id' => $owner->id, 'currency_code' => 'EUR']);
    CreditCardDetail::factory()->create(['account_id' => $card->id, 'statement_closing_date' => '2026-03-05', 'payment_due_date' => '2026-03-20']);
    $expense = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    legacySplitOn($card, -6000, [[$expense, -4000], [$expense, -2000]], attributes: ['transaction_date' => '2026-03-02']);
    legacySplitOn($card, -1200, [[$expense, -700], [$expense, -500]], attributes: ['transaction_date' => '2026-03-12', 'source_date' => '2026-03-01']);
    legacySplitOn($card, 1500, [[$expense, 1000], [$expense, 500]], attributes: ['transaction_date' => '2026-03-08']);
    $figures = fn (): array => app(CreditCardStatementService::class)->figuresOn($card->fresh()->load('creditCardDetail'), CarbonImmutable::parse('2026-03-10'));
    $before = $figures();

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])->assertSuccessful();

    expect(Transaction::query()->whereNotNull('split_parent_id')->count())->toBe(6)
        ->and($figures())->toBe($before);
});

it('previews without writing anything, and prints counts but no transaction', function () {
    legacySplitHousehold();
    $before = conversionTables();

    $this->artisan('transactions:convert-legacy-splits')
        ->expectsOutputToContain('Preview: nothing was written.')
        ->expectsOutputToContain('Legacy splits to convert: 10')
        ->expectsOutputToContain('Already converted: 0')
        ->expectsOutputToContain('Skipped: 4')
        ->expectsOutputToContain('in the trash: 1')
        ->expectsOutputToContain('a part of a split: 1')
        ->expectsOutputToContain('live but already has live parts (restored after a conversion): 0')
        ->expectsOutputToContain('flagged by transactions:audit-splits (parent_has_fewer_than_two_lines): 1')
        ->expectsOutputToContain('flagged by transactions:audit-splits (split_sum_mismatch): 1')
        ->expectsOutputToContain('Parts created: 21')
        ->expectsOutputToContain('FX rounding (accepted): cashflow ')
        ->expectsOutputToContain('Verification passed')
        ->doesntExpectOutputToContain('MERCADONA')
        ->doesntExpectOutputToContain('before ')
        ->assertSuccessful();

    expect(conversionTables())->toBe($before);
});

it('converts every split of the household and leaves every figure as it was, but for FX rounding', function () {
    $world = legacySplitHousehold();
    $owner = $world['owner'];
    $spent = spentByPeriod();
    $summary = fn (string $from, string $to): array => Arr::except(
        app(CashflowAnalyticsService::class)->summary($owner->fresh(), new PeriodComparator(Carbon::parse($from), Carbon::parse($to)))['current'],
        ['budgets'],
    );
    $march = $summary('2026-03-01', '2026-03-31');
    $february = $summary('2026-02-01', '2026-02-28');

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Executed: the changes are saved.')
        ->expectsOutputToContain('Verification passed')
        ->assertSuccessful();

    foreach ($world['splits'] as $name => $original) {
        $parts = Transaction::query()->where('split_parent_id', $original->id)->get();

        expect(Transaction::withTrashed()->find($original->id)->trashed())->toBeTrue("{$name} is still live")
            ->and($parts->sum('amount'))->toBe($original->amount)
            ->and($parts)->toHaveCount($original->splits->count());
    }

    foreach ($world['skipped'] as $name => $row) {
        expect(Transaction::query()->where('split_parent_id', $row->id)->exists())->toBeFalse("{$name} was converted");
    }

    // Every period keeps what it spent: closed, archived, one-off and catch-all ones too.
    expect(spentByPeriod())->toBe($spent)
        ->and($summary('2026-02-01', '2026-02-28'))->toBe($february);

    // The dollar split's lines put the residue on the last one (-3075); each
    // part converts on its own (-3076), so March's expense grows by one cent.
    $after = $summary('2026-03-01', '2026-03-31');
    expect($after['expense'] - $march['expense'])->toBe(1)
        ->and($after['net'] - $march['net'])->toBe(-1)
        ->and(Arr::except($after, ['expense', 'net', 'savings_rate', 'variable_expense', 'fixed_expense', 'general_expense']))
        ->toBe(Arr::except($march, ['expense', 'net', 'savings_rate', 'variable_expense', 'fixed_expense', 'general_expense']))
        ->and(abs(($after['fixed_expense'] + $after['variable_expense'] + $after['general_expense']) - ($march['fixed_expense'] + $march['variable_expense'] + $march['general_expense'])))->toBe(1);

    // The recurring series still reads its charge, once, at its full amount.
    expect($world['series']->transactions()->pluck('transactions.id')->all())->toBe([$world['splits']['recurring']->id]);

    // A second run finds nothing left to convert.
    $tables = conversionTables();

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Legacy splits to convert: 0')
        ->expectsOutputToContain('Already converted: 10')
        ->expectsOutputToContain('Nothing to do.')
        ->assertSuccessful();

    expect(conversionTables())->toBe($tables);
});

it('moves every budget row of a converted split, sharing by part amount only what today\'s tracking cannot explain', function () {
    $world = legacySplitHousehold();
    $periods = $world['periods'];
    $splits = $world['splits'];
    $partsOf = fn (string $name) => Transaction::query()->where('split_parent_id', $splits[$name]->id)->orderBy('amount')->get();
    $held = fn (BudgetPeriod $period, string $name): array => $partsOf($name)
        ->map(fn (Transaction $part): ?int => $period->budgetTransactions()->where('transaction_id', $part->id)->value('amount'))
        ->all();

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true, '-v' => true])
        ->expectsOutputToContain('shared by part amount: transaction '.$splits['closed month']->id.', budget period '.$periods['foodFebruary']->id)
        ->assertSuccessful();

    expect(BudgetTransaction::query()->whereIn('transaction_id', collect($splits)->pluck('id'))->exists())->toBeFalse()
        // A third of each part, as the label claims both whole: 166 + 165 = 331.
        ->and($held($periods['holidaysMarch'], 'third owned'))->toBe([166, 165])
        // Half of 501 rounds up to 251, the row the food budget held.
        ->and($held($periods['foodMarch'], 'half owned'))->toBe([251, null])
        // The archived budget keeps its row, now on the part it tracks.
        ->and($held($periods['funMarch'], 'half owned'))->toBe([null, 250])
        // Closed under older tracking: 2500 is not what today's 2000 explains,
        // so it is shared by part amount, 1666 and the residue 834.
        ->and($held($periods['foodFebruary'], 'closed month'))->toBe([1666, 834]);
});

it('puts converted splits back on their lines with --undo, exactly as they were', function () {
    legacySplitHousehold();
    $before = conversionTables(withUpdatedAt: false);

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])->assertSuccessful();
    $converted = conversionTables();

    $this->artisan('transactions:convert-legacy-splits', ['--undo' => true])
        ->expectsOutputToContain('Converted splits to put back on their lines: 10')
        ->expectsOutputToContain('Verification passed')
        ->assertSuccessful();

    expect(conversionTables())->toBe($converted);

    $this->artisan('transactions:convert-legacy-splits', ['--undo' => true, '--execute' => true])
        ->expectsOutputToContain('Parts removed: 21')
        ->assertSuccessful();

    expect(conversionTables(withUpdatedAt: false))->toBe($before);
});

it('never undoes a split made the new way', function () {
    $owner = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $owner->id]);
    $original = Transaction::factory()->plaintext()->create(['user_id' => $owner->id, 'account_id' => $account->id, 'category_id' => null, 'amount' => -1000]);
    app(TransactionSplitter::class)->split($original, [['amount' => -600], ['amount' => -400]]);

    $this->artisan('transactions:convert-legacy-splits', ['--undo' => true, '--execute' => true])
        ->expectsOutputToContain('Nothing to do.')
        ->assertSuccessful();

    expect(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(2)
        ->and(Transaction::withTrashed()->find($original->id)->trashed())->toBeTrue();
});

it('converts only the user named, by email or id', function (string $by) {
    $world = legacySplitHousehold();
    $partner = $world['partner'];

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true, '--user' => $partner->{$by}])
        ->expectsOutputToContain('Legacy splits to convert: 1')
        ->assertSuccessful();

    expect(Transaction::withTrashed()->find($world['splits']['partner']->id)->trashed())->toBeTrue()
        ->and(Transaction::query()->whereNotNull('split_parent_id')->where('user_id', $world['owner']->id)->count())->toBe(2);
})->with(['email' => 'email', 'id' => 'id']);

it('refuses a user nobody has', function () {
    $this->artisan('transactions:convert-legacy-splits', ['--user' => 'nobody@example.com'])
        ->expectsOutputToContain('No user has that id or email address.')
        ->assertFailed();
});

it('fails, lists what changed and writes nothing when a figure does not add up', function () {
    legacySplitHousehold();
    $before = conversionTables();
    // A conversion that gets one part a cent wrong.
    sabotageConversion(fn (Transaction $original): bool => Transaction::query()->whereKey(partIdsOf($original)[0])->increment('amount') > 0);

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Nothing was written.')
        ->expectsOutputToContain('Verification failed')
        ->expectsOutputToContain('changed: category ')
        ->expectsOutputToContain('changed: account day ')
        ->expectsOutputToContain('parts do not match the lines')
        ->assertFailed();

    expect(conversionTables())->toBe($before);
});

it('leaves no transaction half converted when the run stops on an error', function () {
    legacySplitHousehold();
    $before = conversionTables();
    $converted = 0;
    sabotageConversion(function () use (&$converted): bool {
        if (++$converted === 3) {
            throw new RuntimeException('The database went away.');
        }

        return false;
    });

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Stopped by RuntimeException; nothing was written.')
        ->doesntExpectOutputToContain('The database went away.')
        ->assertFailed();

    expect(conversionTables())->toBe($before);
});

it('fails on a difference only one check sees, names its family and writes nothing', function (Closure $sabotage, string $family) {
    legacySplitHousehold();
    // The factory picks a cadence at random; the cashflow summary reserves
    // only for the budgets whose cadence fits the month it reads.
    Budget::query()->update(['period_type' => BudgetPeriodType::Monthly]);
    $before = conversionTables();
    $allocations = BudgetPeriod::query()->orderBy('id')->pluck('allocated_amount', 'id')->all();
    sabotageConversion($sabotage);

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Nothing was written.')
        ->expectsOutputToContain('Verification failed')
        ->expectsOutputToContain("changed: {$family} ")
        ->assertFailed();

    expect(conversionTables())->toBe($before)
        ->and(BudgetPeriod::query()->orderBy('id')->pluck('allocated_amount', 'id')->all())->toBe($allocations);
})->with([
    'a budget row of a part off by one' => [
        function (Transaction $original): bool {
            $row = DB::table('budget_transactions')->whereIn('transaction_id', partIdsOf($original))->orderBy('transaction_id')->first();

            return $row !== null && DB::table('budget_transactions')
                ->where('transaction_id', $row->transaction_id)
                ->where('budget_period_id', $row->budget_period_id)
                ->increment('amount') > 0;
        },
        'budget period',
    ],
    'a label taken off one part' => [
        fn (Transaction $original): bool => DB::table('label_transaction')->whereIn('transaction_id', partIdsOf($original))->orderBy('transaction_id')->limit(1)->delete() > 0,
        'label',
    ],
    'a part moved to another day of the month' => [
        function (Transaction $original): bool {
            $day = $original->transaction_date->copy()->addDay()->toDateString();

            return Transaction::query()->whereKey(partIdsOf($original)[0])->update(['transaction_date' => $day, 'source_date' => $day]) > 0;
        },
        'account day',
    ],
    'a part moved to another month' => [
        function (Transaction $original): bool {
            $day = $original->transaction_date->copy()->addMonthNoOverflow()->toDateString();

            return Transaction::query()->whereKey(partIdsOf($original)[0])->update(['transaction_date' => $day, 'source_date' => $day]) > 0;
        },
        'native cashflow',
    ],
    // Only the cashflow summary reads it (what running budgets still
    // reserve), in the user's currency, and by far more than the one minor
    // unit per foreign part that FX rounding may move.
    'March\'s budget allocations raised' => [
        fn (): bool => BudgetPeriod::query()->whereDate('start_date', '2026-03-01')->increment('allocated_amount', 10000) > 0,
        'cashflow',
    ],
]);

it('checks every budget period a converted row is held in, whoever owns the budget', function () {
    $world = legacySplitHousehold();
    $owner = $world['owner'];
    $joint = $world['splits']['joint'];
    // The partner's budget holds a row of the owner's joint payment; the
    // owner's run reads no budget of the partner's but this one period.
    $partnerMarch = $world['periods']['partnerMarch'];
    BudgetTransaction::query()->create(['transaction_id' => $joint->id, 'budget_period_id' => $partnerMarch->id, 'amount' => -4000]);
    $before = conversionTables();
    sabotageConversion(fn (Transaction $original): bool => $original->is($joint)
        && DB::table('budget_transactions')->where('budget_period_id', $partnerMarch->id)->whereIn('transaction_id', partIdsOf($original))->limit(1)->increment('amount') > 0);

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true, '--user' => $owner->id])
        ->expectsOutputToContain("changed: budget period {$partnerMarch->id}")
        ->assertFailed();

    expect(conversionTables())->toBe($before);
});

it('skips a split the audit flags as breaking a split, under what it flags, and converts the healthy one next to it', function (Closure $flagged, string $codes) {
    $owner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $owner->id, 'currency_code' => 'EUR']);
    $groceries = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    $dining = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    $healthy = legacySplitOn($account, -1000, [[$groceries, -600], [$dining, -400]]);
    $anomaly = $flagged($account, $groceries, $dining);
    $lines = fn (): array => DB::table('transaction_splits')->where('transaction_id', $anomaly->id)->orderBy('position')->get()->map(fn (object $line): array => (array) $line)->all();
    $untouched = [$anomaly->fresh()?->getAttributes(), $lines()];

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true, '-v' => true])
        ->expectsOutputToContain('Legacy splits to convert: 1')
        ->expectsOutputToContain('Skipped: 1')
        ->expectsOutputToContain("flagged by transactions:audit-splits ({$codes}): 1")
        ->expectsOutputToContain("skipped (flagged by transactions:audit-splits ({$codes})): {$anomaly->id}")
        ->expectsOutputToContain('Verification passed')
        ->assertSuccessful();

    expect(Transaction::withTrashed()->find($healthy->id)->trashed())->toBeTrue()
        ->and(partIdsOf($healthy))->toHaveCount(2)
        ->and(partIdsOf($anomaly))->toBe([])
        ->and([$anomaly->fresh()?->getAttributes(), $lines()])->toBe($untouched);
})->with([
    'a single line' => [
        fn (Account $account, Category $groceries): Transaction => legacySplitOn($account, -1000, [[$groceries, -1000]]),
        'parent_has_fewer_than_two_lines',
    ],
    'lines that do not add up' => [
        fn (Account $account, Category $groceries, Category $dining): Transaction => legacySplitOn($account, -1000, [[$groceries, -600], [$dining, -300]]),
        'split_sum_mismatch',
    ],
    'a zero line' => [
        fn (Account $account, Category $groceries, Category $dining): Transaction => legacySplitOn($account, -1000, [[$groceries, -1000], [$dining, 0]]),
        'split_amount_zero, split_sign_mismatch',
    ],
    'a line moving money the other way' => [
        fn (Account $account, Category $groceries, Category $dining): Transaction => legacySplitOn($account, -1000, [[$groceries, -1500], [$dining, 500]]),
        'split_sign_mismatch',
    ],
    // Only the codes that keep it from converting are named.
    'a line moving money the other way, with a gap in the positions' => [
        function (Account $account, Category $groceries, Category $dining): Transaction {
            $split = legacySplitOn($account, -1000, [[$groceries, -1500], [$dining, 500]]);
            DB::table('transaction_splits')->where('transaction_id', $split->id)->where('position', 1)->update(['position' => 7]);

            return $split;
        },
        'split_sign_mismatch',
    ],
    'a line in a deleted category' => [
        function (Account $account, Category $groceries): Transaction {
            $gone = Category::factory()->create(['user_id' => $account->user_id, 'type' => CategoryType::Expense]);
            $split = legacySplitOn($account, -1000, [[$groceries, -600], [$gone, -400]]);
            // The app refuses it; the data may hold it all the same.
            DB::table('categories')->where('id', $gone->id)->update(['deleted_at' => now()]);

            return $split;
        },
        'category_missing_or_deleted',
    ],
    'a line in another user\'s category' => [
        fn (Account $account, Category $groceries): Transaction => legacySplitOn($account, -1000, [
            [$groceries, -600],
            [Category::factory()->create(['user_id' => User::factory()->create()->id, 'space_id' => $account->space_id, 'type' => CategoryType::Expense]), -400],
        ]),
        'category_owner_or_space_mismatch',
    ],
    'a line in a category of another space' => [
        fn (Account $account, Category $groceries): Transaction => legacySplitOn($account, -1000, [
            [$groceries, -600],
            [Category::factory()->create(['user_id' => $account->user_id, 'space_id' => Space::factory()->create(['owner_id' => $account->user_id, 'personal' => false])->id, 'type' => CategoryType::Expense]), -400],
        ]),
        'category_owner_or_space_mismatch',
    ],
    'lines of an expense and an income category' => [
        function (Account $account, Category $groceries): Transaction {
            $income = Category::factory()->create(['user_id' => $account->user_id, 'type' => CategoryType::Income]);

            return legacySplitOn($account, -1000, [[$groceries, -600], [$income, -400]]);
        },
        'category_type_invalid_or_mixed',
    ],
    'a line in a transfer category' => [
        function (Account $account, Category $groceries): Transaction {
            $transfer = Category::factory()->create(['user_id' => $account->user_id, 'type' => CategoryType::Transfer]);

            return legacySplitOn($account, -1000, [[$transfer, -600], [$transfer, -400]]);
        },
        'category_type_invalid_or_mixed',
    ],
    'a parent that still has a category' => [
        function (Account $account, Category $groceries, Category $dining): Transaction {
            $split = legacySplitOn($account, -1000, [[$groceries, -600], [$dining, -400]]);
            DB::table('transactions')->where('id', $split->id)->update(['category_id' => $dining->id, 'category_source' => CategorySource::Rule->value]);

            return $split;
        },
        'parent_classification_present',
    ],
    'a parent that still has AI provenance' => [
        function (Account $account, Category $groceries, Category $dining): Transaction {
            $split = legacySplitOn($account, -1000, [[$groceries, -600], [$dining, -400]]);
            DB::table('transactions')->where('id', $split->id)->update(['ai_model' => 'some-model', 'ai_confidence' => 0.9]);

            return $split;
        },
        'parent_classification_present',
    ],
]);

it('converts a split the audit flags for what parts do not care about, its parts in the order of its lines', function (Closure $flagged, string $code) {
    $owner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $owner->id, 'currency_code' => 'EUR']);
    $groceries = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    $dining = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    $healthy = legacySplitOn($account, -1000, [[$groceries, -600], [$dining, -400]]);
    $flaggedSplit = $flagged($account, $groceries, $dining);
    $original = $flaggedSplit->fresh();
    $lines = $original->splits->map(fn (TransactionSplit $line): array => [$line->amount, $line->category_id])->all();

    expect(collect(app(AuditTransactionSplitsCommand::class)->audit()['anomalies'])->where('id', $original->id)->pluck('code')->all())->toBe([$code]);

    // Parts are saved one by one: the order their ids were made in is the
    // order they were created in.
    $made = [];
    Str::createUuidsUsing(function () use (&$made) {
        return $made[] = Uuid::uuid7();
    });

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Legacy splits to convert: 2')
        ->expectsOutputToContain('Skipped: 0')
        ->expectsOutputToContain('Verification passed')
        ->assertSuccessful();

    $order = array_flip(array_map(fn ($uuid): string => (string) $uuid, $made));
    $parts = Transaction::query()->where('split_parent_id', $original->id)->get()->sortBy(fn (Transaction $part): int => $order[$part->id])->values();
    $trashed = Transaction::withTrashed()->findOrFail($original->id);

    expect(partIdsOf($healthy))->toHaveCount(2)
        ->and($trashed->trashed())->toBeTrue()
        ->and($parts->map(fn (Transaction $part): array => [$part->amount, $part->category_id])->all())->toBe($lines)
        // The original keeps what it carried, in the trash, for a merge.
        ->and($trashed->only(['category_id', 'category_source', 'ai_model', 'ai_confidence']))
        ->toBe($original->only(['category_id', 'category_source', 'ai_model', 'ai_confidence']));
})->with([
    'lines with gaps in their positions, not in the order they were written' => [
        function (Account $account, Category $groceries, Category $dining): Transaction {
            $split = legacySplitOn($account, -1000, [[$groceries, -500], [$dining, -300], [$groceries, -200]]);
            DB::table('transaction_splits')->where('transaction_id', $split->id)->where('position', 1)->update(['position' => 7]);
            DB::table('transaction_splits')->where('transaction_id', $split->id)->where('position', 2)->update(['position' => 4]);

            return $split;
        },
        'position_not_contiguous',
    ],
]);

it('audits one user\'s splits when asked to, as the conversion of one user does', function () {
    $world = legacySplitHousehold();
    $audit = app(AuditTransactionSplitsCommand::class);
    $partnerSplit = $world['splits']['partner'];

    $everyone = $audit->audit();
    $partner = $audit->audit($world['partner']->id);
    $owner = $audit->audit($world['owner']->id);

    expect($partner)->toBe(['parents_reviewed' => 1, 'lines_reviewed' => 2, 'anomaly_count' => 0, 'anomalies' => []])
        ->and($owner['parents_reviewed'] + $partner['parents_reviewed'])->toBe($everyone['parents_reviewed'])
        ->and($owner['lines_reviewed'] + $partner['lines_reviewed'])->toBe($everyone['lines_reviewed'])
        ->and($owner['anomalies'])->toBe($everyone['anomalies'])
        ->and(collect($owner['anomalies'])->pluck('id')->contains($partnerSplit->id))->toBeFalse();
});

it('skips a live row that has lines and live parts, which converting again would count twice', function () {
    $owner = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $owner->id, 'currency_code' => 'EUR']);
    $groceries = Category::factory()->create(['user_id' => $owner->id, 'type' => CategoryType::Expense]);
    $original = legacySplitOn($account, -1000, [[$groceries, -600], [$groceries, -400]]);
    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])->assertSuccessful();
    // Only a manual restore gets here.
    Transaction::withTrashed()->findOrFail($original->id)->restoreQuietly();
    $before = conversionTables();

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true, '-v' => true])
        ->expectsOutputToContain('Legacy splits to convert: 0')
        ->expectsOutputToContain('live but already has live parts (restored after a conversion): 1')
        ->expectsOutputToContain("skipped (live but already has live parts (restored after a conversion)): {$original->id}")
        ->assertSuccessful();

    expect(conversionTables())->toBe($before)
        ->and(partIdsOf($original))->toHaveCount(2);
});

it('undoes the splits whose parts still match their lines and lists the others by id', function () {
    $world = legacySplitHousehold();
    $splits = $world['splits'];
    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])->assertSuccessful();
    $other = Category::factory()->create(['user_id' => $world['owner']->id]);
    $changed = [
        'recategorized' => $splits['labelled'],
        'relabelled' => $splits['third owned'],
        'part deleted' => $splits['imported'],
        'note edited' => $splits['recurring'],
    ];
    DB::table('transactions')->where('id', partIdsOf($changed['recategorized'])[0])->update(['category_id' => $other->id]);
    DB::table('label_transaction')->where('transaction_id', partIdsOf($changed['relabelled'])[0])->delete();
    DB::table('transactions')->where('id', partIdsOf($changed['part deleted'])[0])->update(['deleted_at' => now()]);
    DB::table('transactions')->where('id', partIdsOf($changed['note edited'])[0])->update(['notes' => 'edited after the conversion']);
    $kept = conversionTables();

    $run = $this->artisan('transactions:convert-legacy-splits', ['--undo' => true, '--execute' => true])
        ->expectsOutputToContain('Executed: the changes are saved.')
        ->expectsOutputToContain('Converted splits to put back on their lines: 6')
        ->expectsOutputToContain('Skipped, parts were changed after conversion: 4')
        ->expectsOutputToContain('Verification passed');

    foreach ($changed as $original) {
        $run->expectsOutputToContain("  skipped: {$original->id}");
    }

    $run->assertSuccessful()->run();

    foreach ($splits as $name => $original) {
        $isChanged = in_array($original->id, collect($changed)->pluck('id')->all(), true);

        expect(Transaction::withTrashed()->find($original->id)->trashed())->toBe($isChanged, "{$name} is not where it should be");
    }

    // What was changed after the conversion is exactly as it was left.
    $after = conversionTables();
    $changedIds = collect($changed)->flatMap(fn (Transaction $original): array => [$original->id, ...Transaction::withTrashed()->where('split_parent_id', $original->id)->pluck('id')->all()])->all();
    $only = fn (array $tables): array => collect($tables['transactions'])->filter(fn (array $row): bool => in_array($row['id'], $changedIds, true))->values()->all();

    expect($only($after))->toBe($only($kept));
});
