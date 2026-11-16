<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @param  array<string, mixed>  $attributes
 */
function apiAggregateRow(Account $account, string $date, int $amount, ?Category $category = null, array $attributes = []): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'currency_code' => $account->currency_code,
        'transaction_date' => $date,
        ...$attributes,
    ]);
}

/**
 * Splits $transaction into lines, each [category, amount], as the fork keeps
 * a split: lines on the one transaction, not child rows.
 *
 * @param  list<array{0: Category, 1: int}>  $lines
 */
function apiAggregateSplit(Transaction $transaction, array $lines): Transaction
{
    foreach ($lines as $position => [$category, $amount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'position' => $position,
        ]);
    }

    return $transaction;
}

/**
 * @param  array<string, mixed>  $query
 */
function apiAggregateUrl(array $query): string
{
    return '/api/v1/analytics/aggregate?'.http_build_query($query);
}

/**
 * A EUR user with a checking account, food (with groceries under it) and
 * salary categories.
 *
 * @return array{user: User, account: Account, food: Category, groceries: Category, salary: Category}
 */
function apiAggregateBook(): array
{
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'name' => 'Main']);
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries', 'parent_id' => $food->id]);
    $salary = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income, 'name' => 'Salary']);

    return compact('user', 'account', 'food', 'groceries', 'salary');
}

describe('grouping', function () {
    it('adds up the transactions by month and category', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'salary' => $salary] = apiAggregateBook();
        apiAggregateRow($account, '2026-09-03', -1_000, $food);
        apiAggregateRow($account, '2026-09-20', -2_500, $food);
        apiAggregateRow($account, '2026-09-28', 300_000, $salary);
        apiAggregateRow($account, '2026-10-02', -4_000, $food);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-09-01', 'to' => '2026-10-31', 'group_by' => ['month', 'category']]))
            ->assertOk()
            ->assertExactJson(['data' => [
                'group_by' => ['month', 'category'],
                'metrics' => ['sum', 'count'],
                'from' => '2026-09-01',
                'to' => '2026-10-31',
                'buckets' => [
                    ['month' => '2026-09', 'category_id' => $food->id, 'category_name' => 'Food', 'currency' => 'EUR', 'sum' => -3_500, 'count' => 2],
                    ['month' => '2026-09', 'category_id' => $salary->id, 'category_name' => 'Salary', 'currency' => 'EUR', 'sum' => 300_000, 'count' => 1],
                    ['month' => '2026-10', 'category_id' => $food->id, 'category_name' => 'Food', 'currency' => 'EUR', 'sum' => -4_000, 'count' => 1],
                    ['month' => '2026-10', 'category_id' => $salary->id, 'category_name' => 'Salary', 'currency' => 'EUR', 'sum' => 0, 'count' => 0],
                ],
            ]]);
    });

    it('works out every metric', function () {
        ['user' => $user, 'account' => $account] = apiAggregateBook();
        apiAggregateRow($account, '2026-10-01', -1_000);
        apiAggregateRow($account, '2026-10-02', -2_001);
        apiAggregateRow($account, '2026-10-03', 500);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'metrics' => 'sum,count,avg,min,max']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['currency' => 'EUR', 'sum' => -2_501, 'count' => 3, 'avg' => -834, 'min' => -2_001, 'max' => 500],
            ]);
    });

    it('fills every period of the range with zeros, in the user\'s currency when nothing was booked', function () {
        ['user' => $user] = apiAggregateBook();

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-08-15', 'to' => '2026-10-02', 'group_by' => ['month'], 'metrics' => ['sum', 'count', 'avg']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['month' => '2026-08', 'currency' => 'EUR', 'sum' => 0, 'count' => 0, 'avg' => null],
                ['month' => '2026-09', 'currency' => 'EUR', 'sum' => 0, 'count' => 0, 'avg' => null],
                ['month' => '2026-10', 'currency' => 'EUR', 'sum' => 0, 'count' => 0, 'avg' => null],
            ]);
    });

    it('names a week by its Monday and a year by its number', function (string $dimension, array $periods) {
        ['user' => $user, 'account' => $account] = apiAggregateBook();
        apiAggregateRow($account, '2026-10-07', -1_000);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-12', 'group_by' => [$dimension]]))
            ->assertOk();

        expect(collect($response->json('data.buckets'))->pluck('sum', $dimension)->all())->toBe($periods);
    })->with([
        'week' => ['week', ['2026-09-28' => 0, '2026-10-05' => -1_000, '2026-10-12' => 0]],
        'year' => ['year', ['2026' => -1_000]],
        'day' => ['day', ['2026-10-01' => 0, '2026-10-02' => 0, '2026-10-03' => 0, '2026-10-04' => 0, '2026-10-05' => 0, '2026-10-06' => 0, '2026-10-07' => -1_000, '2026-10-08' => 0, '2026-10-09' => 0, '2026-10-10' => 0, '2026-10-11' => 0, '2026-10-12' => 0]],
    ]);

    it('groups by type, reading an uncategorized row by its sign', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'salary' => $salary] = apiAggregateBook();
        $savings = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Savings, 'name' => 'Emergency fund']);
        apiAggregateRow($account, '2026-10-01', -1_000, $food);
        apiAggregateRow($account, '2026-10-02', -200);
        apiAggregateRow($account, '2026-10-03', 900);
        apiAggregateRow($account, '2026-10-04', 100_000, $salary);
        apiAggregateRow($account, '2026-10-05', -5_000, $savings);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['type']]))
            ->assertOk();

        expect(collect($response->json('data.buckets'))->pluck('sum', 'type')->all())
            ->toBe(['expense' => -1_200, 'income' => 100_900, 'savings' => -5_000]);
    });

    it('reads a deleted category as no category, in one bucket and by the sign', function () {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = apiAggregateBook();
        $savings = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Savings]);
        $gone = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Old expense']);
        $alsoGone = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income, 'name' => 'Old income']);
        apiAggregateRow($account, '2026-10-01', -100, $gone);
        apiAggregateRow($savings, '2026-10-02', -200, $alsoGone);
        apiAggregateRow($savings, '2026-10-03', 1_000, $salary);
        $gone->delete();
        $alsoGone->delete();

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['category']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['category_id' => null, 'category_name' => null, 'currency' => 'EUR', 'sum' => -300, 'count' => 2],
                ['category_id' => $salary->id, 'category_name' => 'Salary', 'currency' => 'EUR', 'sum' => 1_000, 'count' => 1],
            ]);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['type']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['type' => 'expense', 'currency' => 'EUR', 'sum' => -300, 'count' => 2],
                ['type' => 'income', 'currency' => 'EUR', 'sum' => 1_000, 'count' => 1],
            ]);
    });

    it('groups by label, counting a transaction once under each of its labels', function () {
        ['user' => $user, 'account' => $account] = apiAggregateBook();
        $holidays = Label::factory()->create(['user_id' => $user->id, 'name' => 'Holidays']);
        $work = Label::factory()->create(['user_id' => $user->id, 'name' => 'Work']);
        apiAggregateRow($account, '2026-10-01', -1_000)->labels()->attach([$holidays->id, $work->id]);
        apiAggregateRow($account, '2026-10-02', -300)->labels()->attach($work->id);
        apiAggregateRow($account, '2026-10-03', -50);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['label']]))
            ->assertOk();

        expect(collect($response->json('data.buckets'))->map(fn (array $bucket): array => [$bucket['label_name'], $bucket['sum']])->all())
            ->toBe([[null, -50], ['Holidays', -1_000], ['Work', -1_300]]);

        // Filtered by a label, only that label is listed.
        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['label'], 'label_ids' => [$holidays->id]]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['label_id' => $holidays->id, 'label_name' => 'Holidays', 'currency' => 'EUR', 'sum' => -1_000, 'count' => 1],
            ]);
    });

    it('groups by account and keeps every currency apart', function () {
        ['user' => $user, 'account' => $account] = apiAggregateBook();
        $dollars = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'USD', 'name' => 'Dollars']);
        apiAggregateRow($account, '2026-10-01', -1_000);
        apiAggregateRow($dollars, '2026-10-01', -700);
        apiAggregateRow($account, '2026-10-02', -300, null, ['currency_code' => 'USD']);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['currency' => 'EUR', 'sum' => -1_000, 'count' => 1],
                ['currency' => 'USD', 'sum' => -1_000, 'count' => 2],
            ]);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['account']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['account_id' => $dollars->id, 'account_name' => 'Dollars', 'currency' => 'USD', 'sum' => -700, 'count' => 1],
                ['account_id' => $account->id, 'account_name' => 'Main', 'currency' => 'EUR', 'sum' => -1_000, 'count' => 1],
                ['account_id' => $account->id, 'account_name' => 'Main', 'currency' => 'USD', 'sum' => -300, 'count' => 1],
            ]);
    });

    it('answers an empty name for an account still encrypted on the device', function () {
        ['user' => $user, 'account' => $account] = apiAggregateBook();
        $encrypted = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'name' => 'AAAAc2VjcmV0', 'name_iv' => 'aXZpdml2aXZpdml2', 'encrypted' => true]);
        apiAggregateRow($account, '2026-10-01', -1_000);
        apiAggregateRow($encrypted, '2026-10-02', -300);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['account']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['account_id' => $encrypted->id, 'account_name' => '', 'currency' => 'EUR', 'sum' => -300, 'count' => 1],
                ['account_id' => $account->id, 'account_name' => 'Main', 'currency' => 'EUR', 'sum' => -1_000, 'count' => 1],
            ])
            ->assertDontSee('c2VjcmV0');
    });

    it('counts the user\'s share of the account and stops counting an archived account from its archive day', function () {
        ['user' => $user] = apiAggregateBook();
        $shared = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'ownership_percentage' => 50]);
        $archived = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'archived_at' => '2026-10-10 08:00:00']);
        apiAggregateRow($shared, '2026-10-01', -1_001);
        apiAggregateRow($archived, '2026-10-09', -200);
        apiAggregateRow($archived, '2026-10-10', -9_999);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [['currency' => 'EUR', 'sum' => -701, 'count' => 2]]);
    });
});

describe('filters', function () {
    it('narrows by category, with or without its subcategories', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'groceries' => $groceries, 'salary' => $salary] = apiAggregateBook();
        apiAggregateRow($account, '2026-10-01', -1_000, $food);
        apiAggregateRow($account, '2026-10-02', -2_000, $groceries);
        apiAggregateRow($account, '2026-10-03', 50_000, $salary);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'category_ids' => [$food->id]]))
            ->assertOk()
            ->assertJsonPath('data.buckets.0.sum', -3_000);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'category_ids' => [$food->id], 'include_subcategories' => 'false']))
            ->assertOk()
            ->assertJsonPath('data.buckets.0.sum', -1_000);
    });

    it('narrows by date range, account, label, type and text', function (string $case, int $sum) {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = apiAggregateBook();
        $other = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $label = Label::factory()->create(['user_id' => $user->id]);
        apiAggregateRow($account, '2026-09-30', -1);
        apiAggregateRow($account, '2026-10-01', -20, null, ['description' => 'Coffee shop']);
        apiAggregateRow($other, '2026-10-02', -300)->labels()->attach($label->id);
        apiAggregateRow($account, '2026-10-03', 4_000, $salary);

        $filters = match ($case) {
            'range' => [],
            'account' => ['account_ids' => [$other->id]],
            'label' => ['label_ids' => [$label->id]],
            'type' => ['types' => ['income']],
            'text' => ['query' => 'coffee'],
        };

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', ...$filters]))
            ->assertOk()
            ->assertJsonPath('data.buckets.0.sum', $sum);
    })->with([
        'range' => ['range', 3_680],
        'account' => ['account', -300],
        'label' => ['label', -300],
        'type' => ['type', 4_000],
        'text' => ['text', -20],
    ]);
});

describe('split transactions', function () {
    it('files each line under its own category and counts the transaction once in each', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'salary' => $salary] = apiAggregateBook();
        $home = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Home']);
        apiAggregateSplit(apiAggregateRow($account, '2026-10-05', -10_000), [[$food, -6_000], [$home, -4_000]]);
        apiAggregateRow($account, '2026-10-06', -1_000, $food);
        apiAggregateRow($account, '2026-10-07', 50_000, $salary);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['category'], 'metrics' => 'sum,count,min']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['category_id' => $food->id, 'category_name' => 'Food', 'currency' => 'EUR', 'sum' => -7_000, 'count' => 2, 'min' => -6_000],
                ['category_id' => $home->id, 'category_name' => 'Home', 'currency' => 'EUR', 'sum' => -4_000, 'count' => 1, 'min' => -4_000],
                ['category_id' => $salary->id, 'category_name' => 'Salary', 'currency' => 'EUR', 'sum' => 50_000, 'count' => 1, 'min' => 50_000],
            ]);
    });

    it('adds a split transaction up once, however many lines it has', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'groceries' => $groceries] = apiAggregateBook();
        apiAggregateSplit(apiAggregateRow($account, '2026-10-05', -10_000), [[$food, -6_000], [$groceries, -4_000]]);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['month'], 'metrics' => 'sum,count,avg,max']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['month' => '2026-10', 'currency' => 'EUR', 'sum' => -10_000, 'count' => 1, 'avg' => -10_000, 'max' => -10_000],
            ]);
    });

    it('keeps only the lines a category or type filter matches', function (string $filter, int $sum) {
        ['user' => $user, 'account' => $account, 'food' => $food, 'groceries' => $groceries] = apiAggregateBook();
        $transfer = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Transfer, 'name' => 'Between accounts']);
        apiAggregateSplit(apiAggregateRow($account, '2026-10-05', -10_000), [[$groceries, -6_000], [$transfer, -4_000]]);

        $narrowed = match ($filter) {
            'parent category' => ['category_ids' => [$food->id]],
            'type' => ['types' => ['transfer']],
        };

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', ...$narrowed]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [['currency' => 'EUR', 'sum' => $sum, 'count' => 1]]);
    })->with([
        'parent category' => ['parent category', -6_000],
        'type' => ['type', -4_000],
    ]);

    it('reads each line\'s type, and keeps every line under a label', function () {
        ['user' => $user, 'account' => $account, 'food' => $food] = apiAggregateBook();
        $savings = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Savings, 'name' => 'Rainy day']);
        $trip = Label::factory()->create(['user_id' => $user->id, 'name' => 'Trip']);
        apiAggregateSplit(apiAggregateRow($account, '2026-10-05', -10_000), [[$food, -7_000], [$savings, -3_000]])
            ->labels()->attach($trip->id);

        $byType = withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['type']]))
            ->assertOk();

        expect(collect($byType->json('data.buckets'))->pluck('sum', 'type')->all())->toBe(['expense' => -7_000, 'savings' => -3_000]);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['label'], 'label_ids' => [$trip->id]]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['label_id' => $trip->id, 'label_name' => 'Trip', 'currency' => 'EUR', 'sum' => -10_000, 'count' => 1],
            ]);
    });

    it('weighs each line by the owner\'s share of the account', function () {
        ['user' => $user, 'food' => $food, 'salary' => $salary] = apiAggregateBook();
        $shared = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'ownership_percentage' => 50]);
        apiAggregateSplit(apiAggregateRow($shared, '2026-10-05', -2_002), [[$food, -1_001], [$salary, -1_001]]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['category']]))
            ->assertOk();

        expect(collect($response->json('data.buckets'))->pluck('sum', 'category_name')->all())->toBe(['Food' => -501, 'Salary' => -501]);
    });
});

describe('limits and validation', function () {
    it('refuses more buckets than the cap with a clear error', function (array $query) {
        ['user' => $user, 'account' => $account, 'food' => $food, 'salary' => $salary] = apiAggregateBook();
        apiAggregateRow($account, '2026-01-01', -1_000, $food);
        apiAggregateRow($account, '2026-01-01', 1_000, $salary);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group_by' => 'more than 1000 buckets']);
    })->with([
        'too many days' => [['from' => '2020-01-01', 'to' => '2026-01-01', 'group_by' => ['day']]],
        'too many days per category' => [['from' => '2026-01-01', 'to' => '2027-06-30', 'group_by' => ['day', 'category']]],
    ]);

    it('refuses bad parameters', function (array $query, string $field) {
        ['user' => $user] = apiAggregateBook();

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', ...$query]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'two periods' => [['group_by' => ['day', 'month']], 'group_by'],
        'three dimensions' => [['group_by' => ['month', 'category', 'label']], 'group_by'],
        'unknown dimension' => [['group_by' => ['merchant']], 'group_by.0'],
        'unknown metric' => [['metrics' => ['median']], 'metrics.0'],
        'unknown type' => [['types' => ['refund']], 'types.0'],
        'reversed range' => [['to' => '2026-09-01'], 'to'],
        'unknown parameter' => [['category' => 'food'], 'category'],
    ]);
});

describe('permissions', function () {
    it('adds up only your own transactions of the space', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $owner = User::factory()->create(['currency_code' => 'EUR']);
        $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
        $space->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
        apiAggregateRow(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'currency_code' => 'EUR']), '2026-10-01', -100);
        apiAggregateRow(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'currency_code' => 'EUR']), '2026-10-01', -5_000);
        apiAggregateRow(Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']), '2026-10-01', -7);
        apiAggregateRow(Account::factory()->create(['user_id' => User::factory()->create()->id, 'currency_code' => 'EUR']), '2026-10-01', -9_000);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'space' => $space->id]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [['currency' => 'EUR', 'sum' => -100, 'count' => 1]]);

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [['currency' => 'EUR', 'sum' => -7, 'count' => 1]]);
    });

    it('refuses an account, category or label that is not yours in the space', function (string $field) {
        ['user' => $user] = apiAggregateBook();
        $owner = User::factory()->create();
        $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
        $space->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
        $theirs = match ($field) {
            'account_ids' => Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]),
            'category_ids' => Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]),
            'label_ids' => Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]),
        };

        withHeaders(apiV1Bearer($user))
            ->getJson(apiAggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'space' => $space->id, $field => [$theirs->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors("{$field}.0");
    })->with(['account_ids', 'category_ids', 'label_ids']);
});
