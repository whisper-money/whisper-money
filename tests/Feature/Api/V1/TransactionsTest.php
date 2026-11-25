<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\AccountType;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\TransactionSource;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Features\TransactionSplitting;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\Banking\TransactionDescriptionFormatter;
use App\Services\Banking\TransactionSyncService;
use App\Services\BudgetTransactionService;
use App\Services\Recurring\DetectRecurringSeries;
use App\Services\Transactions\TransactionWriter;
use App\Services\TransactionSplitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function transactionsWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function transactionOn(Account $account, array $attributes = []): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'category_id' => null,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function encryptedTransactionOn(Account $account, array $attributes = []): Transaction
{
    return transactionOn($account, [
        'description' => 'ZGVzY3JpcHRpb24=',
        'description_iv' => 'abcdefghijklmnop',
        'notes' => 'bm90ZXM=',
        'notes_iv' => 'ponmlkjihgfedcba',
        ...$attributes,
    ]);
}

/**
 * @param  array<int, array{0: Category, 1: int}>  $lines  each line's category and amount
 */
function splitTransactionInto(Transaction $transaction, array $lines): Transaction
{
    foreach (array_values($lines) as $position => [$category, $amount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'position' => $position,
        ]);
    }

    return $transaction;
}

function sharedTransactionSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

function budgetPeriodCovering(Budget $budget, string $day): BudgetPeriod
{
    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => $day,
        'end_date' => $day,
    ]);
}

describe('list', function () {
    it('lists the transactions of the personal space, most recent day first', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Main checking', 'currency_code' => 'EUR']);
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
        $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Holidays']);
        $older = transactionOn($account, ['transaction_date' => '2026-01-10']);
        $newer = transactionOn($account, [
            'transaction_date' => '2026-01-20',
            'description' => 'Market',
            'amount' => -4250,
            'currency_code' => 'EUR',
            'category_id' => $category->id,
            'category_source' => CategorySource::Manual,
            'creditor_name' => 'Fresh Market',
            'debtor_name' => null,
            'notes' => null,
        ]);
        $newer->labels()->attach($label);
        transactionOn(Account::factory()->create());

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0', [
                'id' => $newer->id,
                'space_id' => $user->personalSpace->id,
                'account' => ['id' => $account->id, 'name' => 'Main checking'],
                'transaction_date' => '2026-01-20',
                'description' => 'Market',
                'amount' => -4250,
                'currency' => 'EUR',
                'category' => ['id' => $category->id, 'name' => 'Groceries'],
                'category_source' => 'manual',
                'labels' => [['id' => $label->id, 'name' => 'Holidays']],
                'source' => 'manually_created',
                'creditor_name' => 'Fresh Market',
                'debtor_name' => null,
                'notes' => null,
                'split_parent_id' => null,
                'splits' => [],
                'created_at' => $newer->created_at->toIso8601String(),
            ]);
    });

    it('narrows by text, account, dates and amount together', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $other = Account::factory()->create(['user_id' => $user->id]);
        $match = transactionOn($account, ['description' => 'Coffee shop', 'amount' => -350, 'transaction_date' => '2026-02-05']);
        transactionOn($account, ['description' => 'Coffee beans', 'amount' => -350, 'transaction_date' => '2026-03-05']);
        transactionOn($account, ['description' => 'Coffee machine', 'amount' => -90_000, 'transaction_date' => '2026-02-05']);
        transactionOn($other, ['description' => 'Coffee shop', 'amount' => -350, 'transaction_date' => '2026-02-05']);
        transactionOn($account, ['description' => 'Bakery', 'amount' => -350, 'transaction_date' => '2026-02-05']);

        $query = http_build_query([
            'query' => 'coffee',
            'account_id' => $account->id,
            'from' => '2026-02-01',
            'to' => '2026-02-28',
            'min_amount' => -1000,
            'max_amount' => 0,
        ]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?'.$query)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$match->id]);
    });

    it('filters on exact minor units, which the list\'s major units would miss', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $exact = transactionOn($account, ['amount' => 1999]);
        transactionOn($account, ['amount' => 1998]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?min_amount=1999&max_amount=1999')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$exact->id]);
    });

    it('matches the list on a category: its whole subtree, and any id spelling', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $parent = Category::factory()->create(['user_id' => $user->id]);
        $child = Category::factory()->create(['user_id' => $user->id, 'parent_id' => $parent->id]);
        $inParent = transactionOn($account, ['category_id' => $parent->id, 'transaction_date' => '2026-01-02']);
        $inChild = transactionOn($account, ['category_id' => $child->id, 'transaction_date' => '2026-01-01']);
        transactionOn($account, ['category_id' => Category::factory()->create(['user_id' => $user->id])->id]);
        transactionOn($account);

        foreach ([$parent->id, Str::upper($parent->id)] as $id) {
            withHeaders(apiV1Bearer($user))
                ->getJson('/api/v1/transactions?category_id='.$id)
                ->assertOk()
                ->assertJsonPath('data.*.id', [$inParent->id, $inChild->id]);
        }
    });

    it('treats a category and labels as one filter, as the list does', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $categorized = transactionOn($account, ['category_id' => $category->id, 'transaction_date' => '2026-01-02']);
        $labelled = transactionOn($account, ['transaction_date' => '2026-01-01']);
        $labelled->labels()->attach($label);
        transactionOn($account);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?'.http_build_query(['category_id' => $category->id, 'label_ids' => [$label->id]]))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$categorized->id, $labelled->id]);
    });

    it('returns a split transaction as one whole row, whichever line the filter matches', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $home = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $elsewhere = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $split = splitTransactionInto(transactionOn($account, ['amount' => -1000]), [[$food, -600], [$home, -400]]);
        $split->labels()->attach($label);

        foreach (["category_id={$food->id}", "category_id={$home->id}", 'label_ids[]='.$label->id] as $filter) {
            withHeaders(apiV1Bearer($user))
                ->getJson('/api/v1/transactions?'.$filter)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $split->id)
                ->assertJsonPath('data.0.amount', -1000)
                ->assertJsonPath('data.0.category', null)
                ->assertJsonPath('data.0.splits.*.amount', [-600, -400])
                ->assertJsonPath('data.0.splits.*.category.id', [$food->id, $home->id]);
        }

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?category_id='.$elsewhere->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('lists the parts of a split instead of the original', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $original = transactionOn($account, ['amount' => -5000]);
        app(TransactionSplitter::class)->split($original, [['amount' => -3000], ['amount' => -2000]]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        expect($response->json('data.*.split_parent_id'))->toBe([$original->id, $original->id])
            ->and($response->json('data.*.id'))->not->toContain($original->id);
    });

    it('never searches ciphertext, and still finds an encrypted row by what is readable', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $encrypted = encryptedTransactionOn($account, ['creditor_name' => 'Fresh Market']);
        $plain = transactionOn($account, ['description' => 'bm90ZXM= pasted by hand', 'notes' => null]);
        $noted = transactionOn($account, ['description' => 'Lunch', 'notes' => 'with the team']);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?query=ZGVzY3JpcHRpb24')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?query=bm90ZXM')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$plain->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?query=team')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$noted->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?query=fresh')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$encrypted->id])
            ->assertJsonPath('data.0.description', '')
            ->assertJsonPath('data.0.notes', '');
    });

    it('refuses an id of another space and an invalid date', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?from=yesterday-ish&account_id=checking&category_id='.Category::factory()->create()->id.'&label_ids[]='.Label::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'account_id', 'category_id', 'label_ids.0']);
    });

    it('refuses a date whose year cannot be stored, an oversized query and too many labels', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?from=262004-08-10&to=0001-01-01&query='.str_repeat('a', 201).'&'.http_build_query(['label_ids' => array_fill(0, 101, (string) Str::uuid())]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to', 'query', 'label_ids']);
    });

    it('lists only your own transactions of a shared space when asked, and refuses one the user cannot access', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));
        $mine = transactionOn(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]));
        transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/transactions?space='.$space->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mine->id]);

        withHeaders($headers)
            ->getJson('/api/v1/transactions?space='.Space::factory()->create(['owner_id' => $owner->id, 'personal' => false])->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });

    it('pages through transactions sharing a day by id', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $sameDay = collect(range(1, 3))->map(fn (): Transaction => transactionOn($account, ['transaction_date' => '2026-01-20']))
            ->sortByDesc('id')->values();
        $older = transactionOn($account, ['transaction_date' => '2026-01-01']);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/transactions?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$sameDay[0]->id, $sameDay[1]->id]);

        withHeaders($headers)->getJson('/api/v1/transactions?limit=2&starting_after='.$sameDay[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$sameDay[2]->id, $older->id]);

        withHeaders($headers)->getJson('/api/v1/transactions?starting_after='.transactionOn(Account::factory()->create())->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });

    it('reads a page in the same number of queries however many rows it holds', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $seed = function () use ($account, $category, $label): void {
            splitTransactionInto(transactionOn($account, ['amount' => -1000]), [[$category, -600], [$category, -400]])->labels()->attach($label);
        };
        $headers = apiV1Bearer($user);
        $seed();

        $queriesFor = fn (): int => countQueries(fn () => withHeaders($headers)->getJson('/api/v1/transactions')->assertOk())['count'];
        $queriesFor();
        $few = $queriesFor();
        collect(range(1, 5))->each(fn () => $seed());

        expect($queriesFor())->toBe($few);
    });
});

describe('show', function () {
    it('retrieves a transaction of the space', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions/'.$transaction->id)
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id);
    });

    it('answers 404 for a transaction of another user, another space, another member or no transaction at all', function (string $case) {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $id = match ($case) {
            'other user' => transactionOn(Account::factory()->create())->id,
            'other space' => transactionOn(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]))->id,
            'missing' => (string) Str::uuid(),
        };

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions/'.$id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with(['other user', 'other space', 'missing']);

    it('answers 404 for another member\'s transaction of a shared space', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions/'.$theirs->id.'?space='.$space->id)
            ->assertNotFound();
    });
});

describe('create', function () {
    it('creates a manual transaction, defaulting the currency to the account', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);

        $response = withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => $account->id,
                'description' => 'Cash at the market',
                'amount' => -1250,
                'transaction_date' => '2026-01-15',
                'category_id' => $category->id,
                'label_ids' => [$label->id],
                'creditor_name' => 'Market',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.source', 'manually_created')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.category_source', 'manual')
            ->assertJsonPath('data.labels.0.id', $label->id)
            ->assertJsonPath('balance_updated', false);

        $transaction = Transaction::query()->findOrFail($response->json('data.id'));

        expect($transaction->amount)->toBe(-1250)
            ->and($transaction->space_id)->toBe($user->personalSpace->id)
            ->and($transaction->creditor_name)->toBe('Market')
            ->and($transaction->description_iv)->toBeNull()
            ->and($transaction->notes_iv)->toBeNull();
    });

    it('keeps a currency picked apart from the account, in capitals', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => $account->id,
                'description' => 'Hotel',
                'amount' => -4000,
                'transaction_date' => '2026-01-15',
                'currency_code' => 'usd',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'USD');

        expect(Transaction::query()->sole()->getRawOriginal('currency_code'))->toBe('USD');
    });

    it('takes a currency the account picker does not offer, leaving a balance it has no rate for alone', function () {
        fakeCurrencyApi();
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);

        $response = withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => $account->id,
                'description' => 'Oslo',
                'amount' => -4000,
                'transaction_date' => '2026-01-15',
                'currency_code' => 'NOK',
                'update_balance' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'NOK')
            ->assertJsonPath('balance_updated', false);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$response->json('data.id'), ['currency_code' => 'pln'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'PLN');

        expect($account->balances()->sole()->balance)->toBe(10_000);
    });

    it('takes any three ASCII letters and refuses what would break the app\'s money format', function (string $code) {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['currency_code' => 'EUR']);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Odd', 'amount' => -1, 'transaction_date' => '2026-01-15', 'currency_code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency_code');

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['currency_code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency_code');

        expect($transaction->fresh()->currency_code)->toBe('EUR');
    })->with(['N/A', '---', '1!a', 'ßab', 'EU1']);

    it('keeps taking a code no account can be in', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Odd', 'amount' => -1, 'transaction_date' => '2026-01-15', 'currency_code' => 'xyz'])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'XYZ');
    });

    it('answers a number too large to decode with a 422, not a 500', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account);
        $headers = [...transactionsWriter($user), 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $server = collect($headers)->mapWithKeys(fn (string $value, string $name): array => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])->all();
        $raw = fn (string $method, string $url, string $body) => $this->call($method, $url, [], [], [], [...$server, 'CONTENT_TYPE' => 'application/json'], $body);

        $raw('POST', '/api/v1/transactions', '{"account_id":"'.$account->id.'","description":"Odd","transaction_date":"2026-01-15","amount":1e999}')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $raw('PATCH', '/api/v1/transactions/'.$transaction->id, '{"amount":-1e999}')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    });

    it('bounds the amount, on create and on update', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $transaction = transactionOn($account);
        $body = ['account_id' => $account->id, 'description' => 'Big', 'transaction_date' => '2026-01-15', 'update_balance' => true];

        foreach ([PHP_INT_MIN, PHP_INT_MAX, 1_000_000_000_000_001, -1_000_000_000_000_001] as $amount) {
            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [...$body, 'amount' => $amount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, ['amount' => $amount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        foreach ([1_000_000_000_000_000, -1_000_000_000_000_000] as $amount) {
            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [...$body, 'amount' => $amount])
                ->assertCreated()
                ->assertJsonPath('data.amount', $amount);
        }
    });

    it('stores the day of a date sent with a time, and the ids as they are stored', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => Str::upper($account->id),
                'description' => 'Hotel',
                'amount' => -4000,
                'transaction_date' => '2026-01-15T23:30:00+05:00',
                'category_id' => Str::upper($category->id),
                'label_ids' => [Str::upper($label->id)],
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_date', '2026-01-15');

        $transaction = Transaction::query()->sole();

        expect($transaction->getRawOriginal('account_id'))->toBe($account->id)
            ->and($transaction->getRawOriginal('category_id'))->toBe($category->id)
            ->and($transaction->getRawOriginal('transaction_date'))->toBe('2026-01-15')
            ->and(DB::table('label_transaction')->pluck('label_id')->all())->toBe([$label->id]);
    });

    it('moves a manual balance when asked, and leaves a connected one alone', function () {
        $user = User::factory()->create();
        $manual = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $manual->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $connected = Account::factory()->connected()->create(['user_id' => $user->id]);
        $connected->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $body = ['description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15', 'update_balance' => true];

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'account_id' => $manual->id])
            ->assertCreated()
            ->assertJsonPath('balance_updated', true);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'account_id' => $connected->id])
            ->assertCreated()
            ->assertJsonPath('balance_updated', false);

        expect($manual->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(9_900)
            ->and($connected->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(10_000);
    });

    it('locks the account before anything reads a snapshot, and only when the balance moves', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $body = ['account_id' => $account->id, 'description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15'];
        $headers = transactionsWriter($user);

        $moving = statementsOfFirstTransaction(fn () => withHeaders($headers)->postJson('/api/v1/transactions', [...$body, 'update_balance' => true])->assertCreated());

        expect($moving[0])->toContain('`accounts`')->toContain('for update');

        $still = statementsOfFirstTransaction(fn () => withHeaders($headers)->postJson('/api/v1/transactions', $body)->assertCreated());

        expect($still[0] ?? '')->not->toContain('`accounts`');
    });

    it('leaves the balance alone unless asked', function () {
        $user = User::factory()->create();
        $manual = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $manual->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['account_id' => $manual->id, 'description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15'])
            ->assertCreated()
            ->assertJsonPath('balance_updated', false);

        expect($manual->balances()->sole()->balance)->toBe(10_000);
    });

    it('validates the transaction', function () {
        $user = User::factory()->create();

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['amount' => 'a lot', 'currency_code' => 'EURO', 'colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'description', 'amount', 'transaction_date', 'currency_code', 'colour']);
    });

    it('refuses an amount that is not in minor units', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Cash', 'amount' => -12.5, 'transaction_date' => '2026-01-15'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    });

    it('refuses oversized text and a code that is not three characters', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => $account->id,
                'description' => str_repeat('a', 1001),
                'notes' => str_repeat('a', 10001),
                'amount' => -100,
                'transaction_date' => '2026-01-15',
                'currency_code' => 'NOKK',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description', 'notes', 'currency_code']);

        expect(Transaction::query()->count())->toBe(0);
    });

    it('refuses an account, category or label of another user', function () {
        $user = User::factory()->create();

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => Account::factory()->create()->id,
                'description' => 'Lunch',
                'amount' => -1000,
                'transaction_date' => '2026-01-15',
                'category_id' => Category::factory()->create()->id,
                'label_ids' => [Label::factory()->create()->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'category_id', 'label_ids.0']);

        expect(Transaction::query()->count())->toBe(0);
    });

    it('refuses another member\'s account, category and labels in a shared space', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'space' => $space->id,
                'account_id' => Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
                'description' => 'Lunch',
                'amount' => -1000,
                'transaction_date' => '2026-01-15',
                'category_id' => Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
                'label_ids' => [Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'category_id', 'label_ids.0']);
    });

    it('refuses more labels than a transaction can take, and a repeated one', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $body = ['account_id' => $account->id, 'description' => 'Lunch', 'amount' => -1000, 'transaction_date' => '2026-01-15'];

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'label_ids' => array_fill(0, 101, $label->id)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ids');

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'label_ids' => [$label->id, Str::upper($label->id)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ids.0');
    });

    it('needs a write token', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Lunch', 'amount' => -1000, 'transaction_date' => '2026-01-15'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect(Transaction::query()->count())->toBe(0);
    });

    it('creates one transaction for a retried idempotency key', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $headers = [...transactionsWriter($user), 'Idempotency-Key' => 'retry-1'];
        $body = ['account_id' => $account->id, 'description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15', 'update_balance' => true];

        $first = withHeaders($headers)->postJson('/api/v1/transactions', $body)->assertCreated();

        withHeaders($headers)->postJson('/api/v1/transactions', $body)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        expect(Transaction::query()->count())->toBe(1)
            ->and($account->balances()->sole()->balance)->toBe(9_900);
    });

    describe('side effects', function () {
        it('fires the created event', function () {
            Event::fake([TransactionCreated::class]);
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id]);

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Lunch', 'amount' => -1000, 'transaction_date' => '2026-01-15'])
                ->assertCreated();

            Event::assertDispatched(TransactionCreated::class);
        });

        it('runs the automation rules on one with no category, and answers with what they did', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id]);
            $category = Category::factory()->create(['user_id' => $user->id]);
            $ruleLabel = Label::factory()->create(['user_id' => $user->id]);
            $sentLabel = Label::factory()->create(['user_id' => $user->id]);
            $rule = AutomationRule::factory()->create([
                'user_id' => $user->id,
                'priority' => 1,
                'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
                'action_category_id' => $category->id,
            ]);
            $rule->labels()->attach($ruleLabel->id);

            $response = withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Mercadona Alcorcon',
                    'amount' => -1000,
                    'transaction_date' => '2026-01-15',
                    'label_ids' => [$sentLabel->id],
                ])
                ->assertCreated()
                ->assertJsonPath('data.category.id', $category->id)
                ->assertJsonPath('data.category_source', 'rule');

            expect(collect($response->json('data.labels'))->pluck('id')->sort()->values()->all())
                ->toBe(collect([$ruleLabel->id, $sentLabel->id])->sort()->values()->all());
        });

        it('leaves a category sent by hand to the caller, as the app does', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id]);
            $chosen = Category::factory()->create(['user_id' => $user->id]);
            AutomationRule::factory()->create([
                'user_id' => $user->id,
                'priority' => 1,
                'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
                'action_category_id' => Category::factory()->create(['user_id' => $user->id])->id,
            ]);

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Mercadona Alcorcon',
                    'amount' => -1000,
                    'transaction_date' => '2026-01-15',
                    'category_id' => $chosen->id,
                ])
                ->assertCreated()
                ->assertJsonPath('data.category.id', $chosen->id)
                ->assertJsonPath('data.category_source', 'manual');
        });

        it('counts towards the goal a rule tags it for, even with labels of its own', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings]);
            $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
            $rule = AutomationRule::factory()->create([
                'user_id' => $user->id,
                'priority' => 1,
                'rules_json' => ['in' => ['hucha', ['var' => 'description']]],
                'action_category_id' => null,
            ]);
            $rule->labels()->attach($goal->label_id);

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Hucha enero',
                    'amount' => 5000,
                    'transaction_date' => '2026-01-15',
                    'label_ids' => [Label::factory()->create(['user_id' => $user->id])->id],
                ])
                ->assertCreated()
                ->assertJsonCount(2, 'data.labels');

            expect($goal->fresh()->savedAmountInCents())->toBe(5000);
        });

        it('lands in the budget that tracks its category or its labels', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
            $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
            $label = Label::factory()->create(['user_id' => $user->id]);
            $byCategory = budgetPeriodCovering(Budget::factory()->forCategories($category)->create(['user_id' => $user->id]), '2026-01-15');
            $byLabel = budgetPeriodCovering(Budget::factory()->forLabels($label)->create(['user_id' => $user->id]), '2026-01-15');

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Lunch',
                    'amount' => -1000,
                    'transaction_date' => '2026-01-15',
                    'category_id' => $category->id,
                ])
                ->assertCreated();

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Trip',
                    'amount' => -2000,
                    'transaction_date' => '2026-01-15',
                    'label_ids' => [$label->id],
                ])
                ->assertCreated();

            expect((int) $byCategory->budgetTransactions()->sole()->amount)->toBe(1000)
                ->and((int) $byLabel->budgetTransactions()->sole()->amount)->toBe(2000);
        });

        it('gives a labelled one its second budget pass silently, so no limit is announced twice', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id]);
            $label = Label::factory()->create(['user_id' => $user->id]);
            $headers = transactionsWriter($user);
            Queue::fake();

            $response = withHeaders($headers)
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Trip',
                    'amount' => -2000,
                    'transaction_date' => '2026-01-15',
                    'label_ids' => [$label->id],
                ])
                ->assertCreated();

            Queue::assertPushed(
                ReassignTransactionsToBudgets::class,
                fn (ReassignTransactionsToBudgets $job): bool => $job->transactionIds === [$response->json('data.id')] && $job->notify === false,
            );
        });

        it('feeds recurring detection like any other row', function () {
            $user = User::factory()->create(['currency_code' => 'EUR']);
            $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

            foreach (range(4, 1) as $monthsAgo) {
                withHeaders(transactionsWriter($user))
                    ->postJson('/api/v1/transactions', [
                        'account_id' => $account->id,
                        'description' => 'Netflix',
                        'creditor_name' => 'Netflix',
                        'amount' => -1299,
                        'transaction_date' => now()->subMonthsNoOverflow($monthsAgo)->toDateString(),
                    ])
                    ->assertCreated();
            }

            expect(app(DetectRecurringSeries::class)->forUserEverywhere($user))->toBe(1);
        });
    });
});

describe('update', function () {
    it('changes only what is sent on a manual transaction', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $transaction = transactionOn($account, ['description' => 'Before', 'amount' => -100, 'currency_code' => 'EUR', 'notes' => 'keep']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['description' => 'After', 'amount' => -200, 'creditor_name' => 'Shop'])
            ->assertOk()
            ->assertJsonPath('data.description', 'After')
            ->assertJsonPath('data.amount', -200)
            ->assertJsonPath('data.creditor_name', 'Shop')
            ->assertJsonPath('data.notes', 'keep')
            ->assertJsonPath('balance_updated', false);
    });

    it('marks a new category as manual and resets its provenance, and a null clears it', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $before = Category::factory()->create(['user_id' => $user->id]);
        $after = Category::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['category_id' => $before->id, 'category_source' => CategorySource::Ai, 'ai_confidence' => 0.9]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => $after->id])
            ->assertOk()
            ->assertJsonPath('data.category.id', $after->id)
            ->assertJsonPath('data.category_source', 'manual');

        expect($transaction->fresh()->ai_confidence)->toBeNull();

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => null])
            ->assertOk()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.category_source', null);
    });

    it('leaves the provenance of a category sent unchanged', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['category_id' => $category->id, 'category_source' => CategorySource::Ai, 'ai_confidence' => 0.9]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => Str::upper($category->id)])
            ->assertOk()
            ->assertJsonPath('data.category_source', 'ai');

        expect($transaction->fresh()->ai_confidence)->toBe(0.9);
    });

    it('stores a date sent with a time as its day', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-03-02T23:30:00-05:00'])
            ->assertOk()
            ->assertJsonPath('data.transaction_date', '2026-03-02');

        expect($transaction->fresh()->getRawOriginal('transaction_date'))->toBe('2026-03-02');
    });

    it('moves a manual balance off the old day and onto the new one when asked', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $account->balances()->create(['balance_date' => '2026-01-10', 'balance' => 10_000]);
        $account->balances()->create(['balance_date' => '2026-01-20', 'balance' => 10_000]);
        $transaction = transactionOn($account, ['amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-10']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-01-20', 'update_balance' => true])
            ->assertOk()
            ->assertJsonPath('balance_updated', true);

        expect($account->balances()->where('balance_date', '2026-01-10')->value('balance'))->toBe(10_100)
            ->and($account->balances()->where('balance_date', '2026-01-20')->value('balance'))->toBe(10_000);
    });

    it('locks both accounts of a move before anything reads a snapshot', function () {
        $user = User::factory()->create();
        $from = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $to = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $transaction = transactionOn($from, ['currency_code' => 'EUR']);
        $headers = transactionsWriter($user);

        $statements = statementsOfFirstTransaction(fn () => withHeaders($headers)
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['account_id' => $to->id, 'update_balance' => true])
            ->assertOk());

        expect($statements[0])->toContain('`transactions`')->toContain('for update')
            ->and($statements[1])->toContain('`accounts`')->toContain('for update')->toContain('`id` in (?, ?)');
    });

    it('takes the new account\'s currency with the move, and off the old balance', function () {
        $user = User::factory()->create();
        $from = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $from->balances()->create(['balance_date' => '2026-01-10', 'balance' => 10_000]);
        $to = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'USD']);
        $to->balances()->create(['balance_date' => '2026-01-10', 'balance' => 10_000]);
        $transaction = transactionOn($from, ['amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-10']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['account_id' => Str::upper($to->id), 'update_balance' => true])
            ->assertOk()
            ->assertJsonPath('data.account.id', $to->id)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('balance_updated', true);

        expect($transaction->fresh()->getRawOriginal('account_id'))->toBe($to->id)
            ->and($from->balances()->sole()->balance)->toBe(10_100)
            ->and($to->balances()->sole()->balance)->toBe(9_900);
    });

    it('keeps a currency picked in the same edit, and one the account did not change', function () {
        $user = User::factory()->create();
        $from = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $to = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $picked = transactionOn($from, ['currency_code' => 'EUR']);
        $foreign = transactionOn($from, ['currency_code' => 'GBP']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$picked->id, ['account_id' => $to->id, 'currency_code' => 'JPY'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'JPY');

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$foreign->id, ['account_id' => $from->id])
            ->assertOk()
            ->assertJsonPath('data.currency', 'GBP');
    });

    it('moves a bank transaction to another date, keeping the source\'s, and edits its notes and category', function () {
        $user = User::factory()->create();
        $account = Account::factory()->connected()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['source' => TransactionSource::EnableBanking, 'transaction_date' => '2026-01-27']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-02-01', 'notes' => 'Next month', 'category_id' => $category->id])
            ->assertOk()
            ->assertJsonPath('data.transaction_date', '2026-02-01')
            ->assertJsonPath('data.notes', 'Next month')
            ->assertJsonPath('data.category.id', $category->id);

        expect($transaction->fresh()->source_date->toDateString())->toBe('2026-01-27');
    });

    it('does not take sending the same day for moving a bank transaction', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->connected()->create(['user_id' => $user->id]), ['source' => TransactionSource::EnableBanking, 'transaction_date' => '2026-01-27']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-01-27 15:00:00'])
            ->assertOk();

        expect($transaction->fresh()->source_date)->toBeNull();
    });

    it('locks what the source owns on one from a bank or an import, in the body or the query string', function (TransactionSource $source) {
        $user = User::factory()->create();
        $account = Account::factory()->connected()->create(['user_id' => $user->id]);
        $other = Account::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['source' => $source, 'description' => 'Bank said', 'amount' => -100, 'currency_code' => 'EUR', 'notes' => 'keep']);
        $locked = ['description' => 'Mine', 'amount' => -1, 'currency_code' => 'USD', 'account_id' => $other->id, 'creditor_name' => 'Me', 'debtor_name' => 'You'];

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, [...$locked, 'notes' => 'changed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($locked));

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id.'?amount=-1', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        expect($transaction->fresh())
            ->description->toBe('Bank said')
            ->amount->toBe(-100)
            ->notes->toBe('keep');
    })->with([TransactionSource::EnableBanking, TransactionSource::Imported]);

    it('never moves the balance for one from a bank or an import, as the app\'s dialog', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $account->balances()->create(['balance_date' => '2026-01-10', 'balance' => 10_000]);
        $transaction = transactionOn($account, ['source' => TransactionSource::Imported, 'amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-10']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-01-20', 'update_balance' => true])
            ->assertOk()
            ->assertJsonPath('balance_updated', false);

        expect($account->balances()->sole()->balance)->toBe(10_000);
    });

    it('leaves the category and amount of a split transaction to its lines', function (array $body) {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitTransactionInto(transactionOn($account, ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('splits');

        expect($transaction->fresh()->amount)->toBe(-1000)
            ->and($transaction->fresh()->category_id)->toBeNull()
            ->and($transaction->splits()->count())->toBe(2);
    })->with([
        'category' => [['category_id' => null]],
        'amount' => [['amount' => -1]],
    ]);

    it('still moves a split transaction to another date and edits its notes', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitTransactionInto(transactionOn($account, ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-02-01', 'notes' => 'Shared'])
            ->assertOk()
            ->assertJsonPath('data.transaction_date', '2026-02-01')
            ->assertJsonPath('data.notes', 'Shared')
            ->assertJsonCount(2, 'data.splits');
    });

    it('checks for a split again under the lock', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitTransactionInto(transactionOn($account, ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        expect(fn () => app(TransactionWriter::class)->update($transaction, ['amount' => -1], false))
            ->toThrow(ValidationException::class);

        expect($transaction->fresh()->amount)->toBe(-1000);
    });

    it('refuses the core fields of a split part, its date included, saving nothing', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $part = app(TransactionSplitter::class)->split(transactionOn($account, ['amount' => -5000, 'transaction_date' => '2026-01-15']), [['amount' => -3000], ['amount' => -2000]])->first();

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$part->id, ['transaction_date' => '2026-01-01', 'notes' => 'Edited'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_date' => 'one part of a split']);

        expect($part->fresh()->transaction_date->toDateString())->toBe('2026-01-15')
            ->and($part->fresh()->notes)->not->toBe('Edited');
    });

    it('refuses the locked core fields of a split part sent in the query string', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $other = Account::factory()->create(['user_id' => $user->id]);
        $part = app(TransactionSplitter::class)->split(transactionOn($account, ['amount' => -5000]), [['amount' => -3000], ['amount' => -2000]])->first();

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$part->id.'?amount=1&transaction_date=2020-01-01&account_id='.$other->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'transaction_date', 'account_id']);

        expect($part->fresh())->amount->toBe(-3000)->account_id->toBe($account->id);
    });

    it('edits the notes and the category of a split part', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Dining']);
        $part = app(TransactionSplitter::class)->split(transactionOn($account, ['amount' => -5000]), [['amount' => -3000], ['amount' => -2000]])->first();

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$part->id, ['notes' => 'The camera, not the lens', 'category_id' => $category->id])
            ->assertOk()
            ->assertJsonPath('data.notes', 'The camera, not the lens')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.split_parent_id', $part->split_parent_id);
    });

    it('refuses unknown fields, labels included, and a category of another user', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, [
                'label_ids' => [],
                'description_iv' => 'abcdefghijklmnop',
                'source' => 'imported',
                'category_id' => Category::factory()->create()->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label_ids', 'description_iv', 'source', 'category_id']);
    });

    it('answers 404 for another user\'s and another member\'s transaction', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]), ['description' => 'Theirs']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.transactionOn(Account::factory()->create())->id, ['notes' => 'Mine'])
            ->assertNotFound();

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$theirs->id, ['space' => $space->id, 'description' => 'Mine'])
            ->assertNotFound();

        expect($theirs->fresh()->description)->toBe('Theirs');
    });

    it('fires the updated event and moves the transaction between budgets', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $before = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $after = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $beforePeriod = budgetPeriodCovering(Budget::factory()->forCategories($before)->create(['user_id' => $user->id]), '2026-01-15');
        $afterPeriod = budgetPeriodCovering(Budget::factory()->forCategories($after)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'category_id' => $before->id, 'transaction_date' => '2026-01-15']);

        expect($beforePeriod->budgetTransactions()->count())->toBe(1);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => $after->id])
            ->assertOk();

        expect($beforePeriod->budgetTransactions()->count())->toBe(0)
            ->and((int) $afterPeriod->budgetTransactions()->sole()->amount)->toBe(1000);

        Event::fake([TransactionUpdated::class]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['notes' => 'Noted'])
            ->assertOk();

        Event::assertDispatched(TransactionUpdated::class);
    });

    describe('encrypted rows', function () {
        it('clears only the iv of the field it writes', function (string $field, string $iv, string $otherField, string $otherIv) {
            $user = User::factory()->create();
            $transaction = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]));
            $other = $transaction->getRawOriginal($otherField);
            $otherIvValue = $transaction->getRawOriginal($otherIv);

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, [$field => 'Plain text'])
                ->assertOk()
                ->assertJsonPath('data.'.$field, 'Plain text')
                ->assertJsonPath('data.'.$otherField, '');

            $fresh = $transaction->fresh();

            expect($fresh->{$field})->toBe('Plain text')
                ->and($fresh->{$iv})->toBeNull()
                ->and($fresh->{$otherField})->toBe($other)
                ->and($fresh->{$otherIv})->toBe($otherIvValue);
        })->with([
            'description' => ['description', 'description_iv', 'notes', 'notes_iv'],
            'notes' => ['notes', 'notes_iv', 'description', 'description_iv'],
        ]);

        it('touches neither when another field changes', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
            $category = Category::factory()->create(['user_id' => $user->id]);
            $transaction = encryptedTransactionOn($account);
            $stored = fn (Transaction $row): array => [$row->description, $row->description_iv, $row->notes, $row->notes_iv];
            $before = $stored($transaction);

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => $category->id, 'amount' => -1, 'creditor_name' => 'Shop', 'transaction_date' => '2026-02-01'])
                ->assertOk()
                ->assertJsonPath('data.description', '')
                ->assertJsonPath('data.notes', '');

            expect($stored($transaction->fresh()))->toBe($before);
        });

        it('refuses a blank value, so writing back what it read erases nothing', function (array $body, string $field) {
            $user = User::factory()->create();
            $transaction = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]));
            $stored = fn (Transaction $row): array => [$row->description, $row->description_iv, $row->notes, $row->notes_iv];
            $before = $stored($transaction);

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);

            expect($stored($transaction->fresh()))->toBe($before);
        })->with([
            'blank notes' => [['notes' => ''], 'notes'],
            'null notes' => [['notes' => null], 'notes'],
            'spaces in notes' => [['notes' => '   '], 'notes'],
            'blank description' => [['description' => ''], 'description'],
            'null description' => [['description' => null], 'description'],
        ]);

        it('still clears a plain note on a row with an encrypted description', function () {
            $user = User::factory()->create();
            $transaction = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]), ['notes' => 'Plain', 'notes_iv' => null]);

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, ['notes' => null])
                ->assertOk()
                ->assertJsonPath('data.notes', null);

            expect($transaction->fresh()->description_iv)->toBe('abcdefghijklmnop');
        });
    });
});

describe('delete', function () {
    it('deletes a manual transaction, reversing a manual balance when asked', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $transaction = transactionOn($account, ['amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id, ['update_balance' => true])
            ->assertOk()
            ->assertExactJson(['id' => $transaction->id, 'deleted' => true, 'balance_updated' => true]);

        expect($transaction->fresh()->trashed())->toBeTrue()
            ->and($account->balances()->sole()->balance)->toBe(10_100);
    });

    it('locks the account before anything reads a snapshot', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $transaction = transactionOn($account, ['currency_code' => 'EUR']);
        $headers = transactionsWriter($user);

        $statements = statementsOfFirstTransaction(fn () => withHeaders($headers)
            ->deleteJson('/api/v1/transactions/'.$transaction->id, ['update_balance' => true])
            ->assertOk());

        expect($statements[0])->toContain('`transactions`')->toContain('for update')
            ->and($statements[1])->toContain('`accounts`')->toContain('for update');
    });

    it('leaves the balance alone unless asked, and a connected one always', function () {
        $user = User::factory()->create();
        $manual = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $manual->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $connected = Account::factory()->connected()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $connected->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $unasked = transactionOn($manual, ['amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']);
        $bank = transactionOn($connected, ['source' => TransactionSource::EnableBanking, 'amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$unasked->id)
            ->assertOk()
            ->assertJsonPath('balance_updated', false);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$bank->id, ['update_balance' => true])
            ->assertOk()
            ->assertJsonPath('balance_updated', false);

        expect($manual->balances()->sole()->balance)->toBe(10_000)
            ->and($connected->balances()->sole()->balance)->toBe(10_000);
    });

    it('deletes a bank transaction, which the next sync does not bring back', function () {
        $user = User::factory()->onboarded()->create();
        $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->connected()->create([
            'user_id' => $user->id,
            'banking_connection_id' => $connection->id,
            'external_account_id' => 'ext-123',
        ]);
        $transaction = Transaction::factory()->enableBanking()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'external_transaction_id' => 'txn-001',
        ]);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $provider = Mockery::mock(BankingProviderInterface::class);
        $provider->shouldReceive('getTransactions')->once()->andReturn([
            'transactions' => [[
                'transaction_id' => 'txn-001',
                'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
                'credit_debit_indicator' => 'DBIT',
                'booking_date' => '2025-01-15',
                'remittance_information' => ['Grocery Store Purchase'],
            ]],
            'continuation_key' => null,
        ]);

        $created = (new TransactionSyncService($provider, new TransactionDescriptionFormatter))->sync($account, '2025-01-01', '2025-01-31');

        expect($created)->toBe(0)
            ->and($account->transactions()->count())->toBe(0)
            ->and($account->transactions()->withTrashed()->count())->toBe(1);
    });

    it('takes a split transaction out of its budgets, and fires the deleted event', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $period = budgetPeriodCovering(Budget::factory()->forCategories($food)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = splitTransactionInto(transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']), [[$food, -600], [$food, -400]]);
        app(BudgetTransactionService::class)->assignTransaction($transaction->fresh());

        expect((int) $period->budgetTransactions()->sole()->amount)->toBe(1000);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id)
            ->assertOk();

        expect($period->budgetTransactions()->count())->toBe(0);

        Event::fake([TransactionDeleted::class]);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.transactionOn($account)->id)
            ->assertOk();

        Event::assertDispatched(TransactionDeleted::class);
    });

    it('refuses a split part, leaving it and its siblings in place', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $parts = app(TransactionSplitter::class)->split(transactionOn($account, ['amount' => -5000]), [['amount' => -3000], ['amount' => -2000]]);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$parts->first()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction' => 'Merge the split back first.']);

        expect(Transaction::query()->whereKey($parts->pluck('id'))->count())->toBe(2);
    });

    it('refuses unknown fields, and a second delete is a 404', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id, ['force' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('force');

        expect($transaction->fresh()->trashed())->toBeFalse();

        withHeaders(transactionsWriter($user))->deleteJson('/api/v1/transactions/'.$transaction->id)->assertOk();
        withHeaders(transactionsWriter($user))->deleteJson('/api/v1/transactions/'.$transaction->id)->assertNotFound();
    });

    it('answers 404 for another user\'s and another member\'s transaction', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.transactionOn(Account::factory()->create())->id)
            ->assertNotFound();

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$theirs->id.'?space='.$space->id)
            ->assertNotFound();

        expect($theirs->fresh()->trashed())->toBeFalse();
    });
});

describe('labels', function () {
    it('makes the sent labels the only ones, and an empty list clears them', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $kept = Label::factory()->create(['user_id' => $user->id]);
        $dropped = Label::factory()->create(['user_id' => $user->id]);
        $added = Label::factory()->create(['user_id' => $user->id]);
        $transaction->labels()->attach([$kept->id, $dropped->id]);

        $response = withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$kept->id, Str::upper($added->id)]])
            ->assertOk()
            ->assertJsonCount(2, 'data.labels');

        expect(collect($response->json('data.labels'))->pluck('id')->sort()->values()->all())
            ->toBe(collect([$kept->id, $added->id])->sort()->values()->all())
            ->and(DB::table('label_transaction')->where('transaction_id', $transaction->id)->pluck('label_id')->sort()->values()->all())
            ->toBe(collect([$kept->id, $added->id])->sort()->values()->all());

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.labels');

        expect($transaction->labels()->count())->toBe(0);
    });

    it('works on a bank row, a split one and an encrypted one, touching nothing else', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $bank = transactionOn(Account::factory()->connected()->create(['user_id' => $user->id]), ['source' => TransactionSource::EnableBanking]);
        $split = splitTransactionInto(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [[$category, -600], [$category, -400]]);
        $encrypted = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]));
        $notes = $encrypted->notes;

        foreach ([$bank, $split, $encrypted] as $transaction) {
            withHeaders(transactionsWriter($user))
                ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$label->id]])
                ->assertOk()
                ->assertJsonPath('data.labels.0.id', $label->id);
        }

        expect($split->splits()->count())->toBe(2)
            ->and($encrypted->fresh()->notes)->toBe($notes)
            ->and($encrypted->fresh()->notes_iv)->toBe('ponmlkjihgfedcba')
            ->and($encrypted->fresh()->description_iv)->toBe('abcdefghijklmnop');
    });

    it('moves the row in the app\'s delta sync, without firing an event or a budget email', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $label = Label::factory()->create(['user_id' => $user->id]);
        $before = $transaction->fresh()->updated_at;
        Event::fake([TransactionUpdated::class]);
        Queue::fake();

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$label->id]])
            ->assertOk();

        expect($transaction->fresh()->updated_at->gt($before))->toBeTrue();

        Event::assertNotDispatched(TransactionUpdated::class);
        Queue::assertPushed(
            ReassignTransactionsToBudgets::class,
            fn (ReassignTransactionsToBudgets $job): bool => $job->transactionIds === [$transaction->id] && $job->notify === false,
        );
    });

    it('moves the transaction into the budget that tracks the label', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $period = budgetPeriodCovering(Budget::factory()->forLabels($label)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']);

        expect($period->budgetTransactions()->count())->toBe(0);

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$label->id]])
            ->assertOk();

        expect((int) $period->budgetTransactions()->sole()->amount)->toBe(1000);

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => []])
            ->assertOk();

        expect($period->budgetTransactions()->count())->toBe(0);
    });

    it('counts towards a savings goal once it carries the goal\'s label', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings, 'currency_code' => 'EUR']);
        $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['amount' => 5000, 'currency_code' => 'EUR']);

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$goal->label_id]])
            ->assertOk();

        expect($goal->fresh()->savedAmountInCents())->toBe(5000);
    });

    it('refuses a missing list, unknown fields, repeats, too many and labels that are not yours', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]));
        $url = '/api/v1/transactions/'.$transaction->id.'/labels?space='.$space->id;
        $mine = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

        withHeaders(transactionsWriter($user))->putJson($url, ['colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label_ids', 'colour']);

        withHeaders(transactionsWriter($user))->putJson($url, ['label_ids' => [$mine->id, Str::upper($mine->id)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ids.0');

        withHeaders(transactionsWriter($user))->putJson($url, ['label_ids' => array_fill(0, 101, $mine->id)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ids')
            ->assertJsonMissingValidationErrors('label_ids.0');

        withHeaders(transactionsWriter($user))->putJson($url, ['label_ids' => [
            Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
            Label::factory()->create(['user_id' => $user->id])->id,
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label_ids.0', 'label_ids.1']);

        expect($transaction->labels()->count())->toBe(0);
    });

    it('needs a write token, and answers 404 for another member\'s transaction', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $mine = transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));

        withHeaders(apiV1Bearer($user))
            ->putJson('/api/v1/transactions/'.$mine->id.'/labels', ['label_ids' => []])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$theirs->id.'/labels?space='.$space->id, ['label_ids' => []])
            ->assertNotFound();
    });
});

describe('split', function () {
    it('replaces a bank transaction with its parts, each with its own category and labels', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $original = Transaction::factory()->imported()->plaintext()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => -5000, 'transaction_date' => '2026-01-15']);
        $original->labels()->attach(Label::factory()->create(['user_id' => $user->id]));
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
        $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Holidays']);
        $headers = transactionsWriter($user);

        withHeaders($headers)
            ->postJson('/api/v1/transactions/'.$original->id.'/split', ['splits' => [
                ['amount' => -3000, 'category_id' => Str::upper($category->id), 'label_ids' => [Str::upper($label->id)]],
                ['amount' => -2000],
            ]])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.amount', -3000)
            ->assertJsonPath('data.0.category', ['id' => $category->id, 'name' => 'Groceries'])
            ->assertJsonPath('data.0.category_source', 'manual')
            ->assertJsonPath('data.0.labels', [['id' => $label->id, 'name' => 'Holidays']])
            ->assertJsonPath('data.0.split_parent_id', $original->id)
            ->assertJsonPath('data.0.splits', [])
            ->assertJsonPath('data.1.amount', -2000)
            ->assertJsonPath('data.1.category', null)
            ->assertJsonPath('data.1.labels', [])
            ->assertJsonPath('data.1.split_parent_id', $original->id);

        expect(Transaction::query()->find($original->id))->toBeNull()
            ->and($original->fresh()->trashed())->toBeTrue()
            ->and($account->balances()->sole()->balance)->toBe(10_000);

        $listed = withHeaders($headers)->getJson('/api/v1/transactions')->json('data.*.split_parent_id');
        expect($listed)->toBe([$original->id, $original->id]);

        withHeaders($headers)
            ->postJson('/api/v1/transactions/'.$original->id.'/split', ['splits' => [['amount' => -3000], ['amount' => -2000]]])
            ->assertNotFound();
    });

    it('refuses parts that do not add up or move money the other way, saving nothing', function (array $splits, string $error) {
        $user = User::factory()->create();
        $original = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -5000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$original->id.'/split', ['splits' => $splits])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['splits' => $error]);

        expect($original->fresh()->trashed())->toBeFalse()
            ->and(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);
    })->with([
        'not adding up' => [[['amount' => -3000], ['amount' => -1000]], 'must add up to the original amount of -5000'],
        'opposite sign' => [[['amount' => -6000], ['amount' => 1000]], 'move money the same way'],
        'a zero part' => [[['amount' => -5000], ['amount' => 0]], 'Every part of a split needs an amount.'],
        'a single part' => [[['amount' => -5000]], 'at least 2 items'],
        'no parts' => [[], 'required'],
    ]);

    it('refuses a part of a split, a category of another space and a field it does not take', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $part = app(TransactionSplitter::class)->split(transactionOn($account, ['amount' => -5000]), [['amount' => -3000], ['amount' => -2000]])->first();
        $headers = transactionsWriter($user);

        withHeaders($headers)
            ->postJson('/api/v1/transactions/'.$part->id.'/split', ['splits' => [['amount' => -1000], ['amount' => -2000]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction' => 'already one part of a split']);

        withHeaders($headers)
            ->postJson('/api/v1/transactions/'.transactionOn($account, ['amount' => -5000])->id.'/split', [
                'splits' => [
                    ['amount' => -3000, 'category_id' => Category::factory()->create()->id],
                    ['amount' => -2000, 'label_ids' => [Label::factory()->create()->id]],
                ],
                'parts' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['splits.0.category_id', 'splits.1.label_ids.0', 'parts']);
    });

    it('refuses a field a part does not have', function () {
        $user = User::factory()->create();
        $original = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -5000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$original->id.'/split', ['splits' => [
                ['amount' => -3000, 'position' => 0],
                ['amount' => -2000],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['splits.0' => 'A part takes only amount, category_id and label_ids.']);

        expect(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);
    });

    it('refuses more than 100 labels on a part and a label sent twice in a part', function () {
        $user = User::factory()->create();
        $original = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -5000]);
        $label = Label::factory()->create(['user_id' => $user->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$original->id.'/split', ['splits' => [
                ['amount' => -3000, 'label_ids' => array_map(fn (): string => (string) Str::uuid7(), range(1, 101))],
                ['amount' => -2000, 'label_ids' => [$label->id, mb_strtoupper($label->id)]],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['splits.0.label_ids' => 'must not have more than 100 items', 'splits.1.label_ids' => 'A label can only be given once per part.']);

        expect(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);
    });

    it('puts the same label on several parts of a split', function () {
        $user = User::factory()->create();
        $original = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -5000]);
        $label = Label::factory()->create(['user_id' => $user->id, 'space_id' => $original->space_id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$original->id.'/split', ['splits' => [
                ['amount' => -3000, 'label_ids' => [$label->id]],
                ['amount' => -2000, 'label_ids' => [$label->id]],
            ]])
            ->assertCreated();

        expect($label->transactions()->where('split_parent_id', $original->id)->count())->toBe(2);
    });

    it('refuses a transaction still split into the old lines until it is converted', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitTransactionInto(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [['amount' => -500], ['amount' => -500]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction' => 'converted']);

        expect($transaction->fresh()->trashed())->toBeFalse()
            ->and($transaction->splits()->count())->toBe(2)
            ->and(Transaction::query()->where('split_parent_id', $transaction->id)->count())->toBe(0);
    });

    it('moves the budgets from the original to the parts', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $home = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $foodPeriod = budgetPeriodCovering(Budget::factory()->forCategories($food)->create(['user_id' => $user->id]), '2026-01-15');
        $homePeriod = budgetPeriodCovering(Budget::factory()->forCategories($home)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15', 'category_id' => $food->id]);

        expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(1000);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [
                ['category_id' => $food->id, 'amount' => -600],
                ['category_id' => $home->id, 'amount' => -400],
            ]])
            ->assertCreated();

        expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(600)
            ->and($foodPeriod->budgetTransactions()->sole()->transaction_id)->not->toBe($transaction->id)
            ->and((int) $homePeriod->budgetTransactions()->sole()->amount)->toBe(400);
    });

    it('copies an encrypted description and notes onto every part', function () {
        $user = User::factory()->create();
        $transaction = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]);
        $stored = fn (Transaction $row): array => [$row->description, $row->description_iv, $row->notes, $row->notes_iv];
        $before = $stored($transaction);

        $parts = withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [['amount' => -600], ['amount' => -400]]])
            ->assertCreated()
            ->assertJsonPath('data.0.description', '')
            ->assertJsonPath('data.0.notes', '')
            ->json('data.*.id');

        foreach (Transaction::query()->whereKey($parts)->get() as $part) {
            expect($stored($part))->toBe($before);
        }

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$parts[0].'/merge-splits')
            ->assertOk()
            ->assertJsonPath('data.description', '');

        expect($stored($transaction->fresh()))->toBe($before);
    });

    it('bounds the parts before looking any of them up', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -21]);
        $headers = transactionsWriter($user);

        $queries = countQueries(fn () => withHeaders($headers)
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => array_fill(0, 21, ['category_id' => $category->id, 'amount' => -1])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('splits'));

        expect(collect($queries['queries'])->filter(fn (string $sql): bool => str_contains($sql, '`categories`')))->toBeEmpty();
    });

    it('takes twenty parts', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -20]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => array_fill(0, 20, ['category_id' => $category->id, 'amount' => -1])])
            ->assertCreated()
            ->assertJsonCount(20, 'data');
    });

    it('answers 403 while splitting is off, and writes nothing', function () {
        $user = User::factory()->create();
        Feature::for($user)->deactivate(TransactionSplitting::class);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [['amount' => -600], ['amount' => -400]]])
            ->assertForbidden()
            ->assertJsonPath('code', 'splitting_disabled');

        expect($transaction->fresh()->trashed())->toBeFalse()
            ->and(Transaction::query()->where('split_parent_id', $transaction->id)->count())->toBe(0);
    });

    it('answers 404 for a transaction of another user and refuses a read-only token', function () {
        $user = User::factory()->create();
        $mine = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -5000]);
        $body = ['splits' => [['amount' => -3000], ['amount' => -2000]]];

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.transactionOn(Account::factory()->create(), ['amount' => -5000])->id.'/split', $body)
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/transactions/'.$mine->id.'/split', $body)
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($mine->fresh()->trashed())->toBeFalse();
    });

    it('replays a split with the same idempotency key instead of failing on the second try', function () {
        $user = User::factory()->create();
        $original = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -5000]);
        $headers = [...transactionsWriter($user), 'Idempotency-Key' => 'split-dinner'];
        $body = ['splits' => [['amount' => -3000], ['amount' => -2000]]];

        $first = withHeaders($headers)->postJson('/api/v1/transactions/'.$original->id.'/split', $body)->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/transactions/'.$original->id.'/split', $body)->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.*.id'))->toBe($first->json('data.*.id'))
            ->and(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(2);
    });
});

describe('split and merge in a shared space', function () {
    it('answers 404 for splitting or merging another member\'s transaction, and refuses their category and labels on your parts', function () {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($member, $owner);
        $theirAccount = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $theirs = transactionOn($theirAccount, ['amount' => -5000]);
        $theirParts = app(TransactionSplitter::class)->split(transactionOn($theirAccount, ['amount' => -5000]), [['amount' => -3000], ['amount' => -2000]]);
        $mine = transactionOn(Account::factory()->create(['user_id' => $member->id, 'space_id' => $space->id]), ['amount' => -5000]);
        $theirCategory = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $theirLabel = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $headers = transactionsWriter($member);

        withHeaders($headers)
            ->postJson('/api/v1/transactions/'.$theirs->id.'/split?space='.$space->id, ['splits' => [['amount' => -3000], ['amount' => -2000]]])
            ->assertNotFound();

        withHeaders($headers)
            ->postJson('/api/v1/transactions/'.$theirParts->first()->id.'/merge-splits?space='.$space->id)
            ->assertNotFound();

        withHeaders($headers)
            ->postJson('/api/v1/transactions/'.$mine->id.'/split?space='.$space->id, ['splits' => [
                ['amount' => -3000, 'category_id' => $theirCategory->id],
                ['amount' => -2000, 'label_ids' => [$theirLabel->id]],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['splits.0.category_id' => 'of yours', 'splits.1.label_ids.0' => 'of yours']);

        expect($theirs->fresh()->isSplitPart())->toBeFalse()
            ->and(Transaction::query()->whereKey($theirParts->pluck('id')->all())->count())->toBe(2)
            ->and($mine->fresh()->trashed())->toBeFalse();
    });
});

describe('merge splits', function () {
    it('puts the original back from any of its parts', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);
        $original = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -5000, 'category_id' => $category->id]);
        $parts = app(TransactionSplitter::class)->split($original, [['amount' => -3000], ['amount' => -2000]]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$parts->last()->id.'/merge-splits')
            ->assertOk()
            ->assertJsonPath('data.id', $original->id)
            ->assertJsonPath('data.amount', -5000)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.split_parent_id', null);

        expect(Transaction::query()->whereKey($parts->pluck('id'))->count())->toBe(0)
            ->and($original->fresh()->trashed())->toBeFalse();
    });

    it('moves the budgets back to the whole amount', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $home = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $foodPeriod = budgetPeriodCovering(Budget::factory()->forCategories($food)->create(['user_id' => $user->id]), '2026-01-15');
        $homePeriod = budgetPeriodCovering(Budget::factory()->forCategories($home)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15', 'category_id' => $food->id]);
        $parts = app(TransactionSplitter::class)->split($transaction, [['category_id' => $food->id, 'amount' => -600], ['category_id' => $home->id, 'amount' => -400]]);

        expect((int) $homePeriod->budgetTransactions()->sole()->amount)->toBe(400);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$parts->first()->id.'/merge-splits')
            ->assertOk();

        expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(1000)
            ->and($foodPeriod->budgetTransactions()->sole()->transaction_id)->toBe($transaction->id)
            ->and($homePeriod->budgetTransactions()->count())->toBe(0);
    });

    it('stays open while splitting is off', function () {
        $user = User::factory()->create();
        $parts = app(TransactionSplitter::class)->split(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [['amount' => -600], ['amount' => -400]]);
        Feature::for($user)->deactivate(TransactionSplitting::class);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$parts->first()->id.'/merge-splits')
            ->assertOk();

        expect(Transaction::query()->whereKey($parts->pluck('id'))->count())->toBe(0);
    });

    it('refuses a transaction that is not part of a split and a field it does not take', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', ['force' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction' => 'not part of a split', 'force']);
    });

    it('refuses a transaction still split into the old lines, and the category the old merge took', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitTransactionInto(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', ['category_id' => $category->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction' => 'not part of a split', 'category_id']);

        expect($transaction->splits()->count())->toBe(2)
            ->and($transaction->fresh()->category_id)->toBeNull();
    });

    it('answers 404 for a transaction of another user and refuses a read-only token', function () {
        $user = User::factory()->create();
        $parts = app(TransactionSplitter::class)->split(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -5000]), [['amount' => -3000], ['amount' => -2000]]);
        $theirs = app(TransactionSplitter::class)->split(transactionOn(Account::factory()->create(), ['amount' => -5000]), [['amount' => -3000], ['amount' => -2000]]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$theirs->first()->id.'/merge-splits')
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/transactions/'.$parts->first()->id.'/merge-splits')
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect(Transaction::query()->whereKey($parts->pluck('id'))->count())->toBe(2);
    });

    it('merges once for a retried idempotency key', function () {
        $user = User::factory()->create();
        $parts = app(TransactionSplitter::class)->split(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [['amount' => -600], ['amount' => -400]]);
        $headers = [...transactionsWriter($user), 'Idempotency-Key' => 'merge-1'];

        withHeaders($headers)->postJson('/api/v1/transactions/'.$parts->first()->id.'/merge-splits')->assertOk();

        withHeaders($headers)->postJson('/api/v1/transactions/'.$parts->first()->id.'/merge-splits')
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');
    });
});
