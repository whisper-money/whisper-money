<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @param  array<string, mixed>  $attributes
 */
function aggregateRow(Account $account, string $date, int $amount, ?Category $category = null, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
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
 * @param  array<string, mixed>  $query
 */
function aggregateUrl(array $query): string
{
    return '/api/v1/analytics/aggregate?'.http_build_query($query);
}

/**
 * A EUR user with a checking account, food (with groceries under it) and
 * salary categories.
 *
 * @return array{user: User, account: Account, food: Category, groceries: Category, salary: Category}
 */
function aggregateBook(): array
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
        ['user' => $user, 'account' => $account, 'food' => $food, 'salary' => $salary] = aggregateBook();
        aggregateRow($account, '2026-09-03', -1_000, $food);
        aggregateRow($account, '2026-09-20', -2_500, $food);
        aggregateRow($account, '2026-09-28', 300_000, $salary);
        aggregateRow($account, '2026-10-02', -4_000, $food);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-09-01', 'to' => '2026-10-31', 'group_by' => ['month', 'category']]))
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
        ['user' => $user, 'account' => $account] = aggregateBook();
        aggregateRow($account, '2026-10-01', -1_000);
        aggregateRow($account, '2026-10-02', -2_001);
        aggregateRow($account, '2026-10-03', 500);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'metrics' => 'sum,count,avg,min,max']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['currency' => 'EUR', 'sum' => -2_501, 'count' => 3, 'avg' => -834, 'min' => -2_001, 'max' => 500],
            ]);
    });

    it('fills every period of the range with zeros, in the user\'s currency when nothing was booked', function () {
        ['user' => $user] = aggregateBook();

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-08-15', 'to' => '2026-10-02', 'group_by' => ['month'], 'metrics' => ['sum', 'count', 'avg']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['month' => '2026-08', 'currency' => 'EUR', 'sum' => 0, 'count' => 0, 'avg' => null],
                ['month' => '2026-09', 'currency' => 'EUR', 'sum' => 0, 'count' => 0, 'avg' => null],
                ['month' => '2026-10', 'currency' => 'EUR', 'sum' => 0, 'count' => 0, 'avg' => null],
            ]);
    });

    it('names a week by its Monday and a year by its number', function (string $dimension, array $periods) {
        ['user' => $user, 'account' => $account] = aggregateBook();
        aggregateRow($account, '2026-10-07', -1_000);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-12', 'group_by' => [$dimension]]))
            ->assertOk();

        expect(collect($response->json('data.buckets'))->pluck('sum', $dimension)->all())->toBe($periods);
    })->with([
        'week' => ['week', ['2026-09-28' => 0, '2026-10-05' => -1_000, '2026-10-12' => 0]],
        'year' => ['year', ['2026' => -1_000]],
        'day' => ['day', ['2026-10-01' => 0, '2026-10-02' => 0, '2026-10-03' => 0, '2026-10-04' => 0, '2026-10-05' => 0, '2026-10-06' => 0, '2026-10-07' => -1_000, '2026-10-08' => 0, '2026-10-09' => 0, '2026-10-10' => 0, '2026-10-11' => 0, '2026-10-12' => 0]],
    ]);

    it('groups by type, reading an uncategorized row by its sign', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'salary' => $salary] = aggregateBook();
        $savings = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Savings]);
        aggregateRow($account, '2026-10-01', -1_000, $food);
        aggregateRow($account, '2026-10-02', -200);
        aggregateRow($account, '2026-10-03', 900);
        aggregateRow($account, '2026-10-04', 100_000, $salary);
        aggregateRow($account, '2026-10-05', -5_000, $savings);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['type']]))
            ->assertOk();

        expect(collect($response->json('data.buckets'))->pluck('sum', 'type')->all())
            ->toBe(['expense' => -1_200, 'income' => 100_900, 'savings' => -5_000]);
    });

    it('reads a deleted category as no category, in one bucket and by the sign', function () {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = aggregateBook();
        $savings = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Savings]);
        $gone = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $alsoGone = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income]);
        aggregateRow($account, '2026-10-01', -100, $gone);
        aggregateRow($savings, '2026-10-02', -200, $alsoGone);
        aggregateRow($savings, '2026-10-03', 1_000, $salary);
        $gone->delete();
        $alsoGone->delete();

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['category']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['category_id' => null, 'category_name' => null, 'currency' => 'EUR', 'sum' => -300, 'count' => 2],
                ['category_id' => $salary->id, 'category_name' => 'Salary', 'currency' => 'EUR', 'sum' => 1_000, 'count' => 1],
            ]);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['type']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['type' => 'expense', 'currency' => 'EUR', 'sum' => -300, 'count' => 2],
                ['type' => 'income', 'currency' => 'EUR', 'sum' => 1_000, 'count' => 1],
            ]);
    });

    it('groups by label, counting a transaction once under each of its labels', function () {
        ['user' => $user, 'account' => $account] = aggregateBook();
        $holidays = Label::factory()->create(['user_id' => $user->id, 'name' => 'Holidays']);
        $work = Label::factory()->create(['user_id' => $user->id, 'name' => 'Work']);
        aggregateRow($account, '2026-10-01', -1_000)->labels()->attach([$holidays->id, $work->id]);
        aggregateRow($account, '2026-10-02', -300)->labels()->attach($work->id);
        aggregateRow($account, '2026-10-03', -50);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['label']]))
            ->assertOk();

        expect(collect($response->json('data.buckets'))->map(fn (array $bucket): array => [$bucket['label_name'], $bucket['sum']])->all())
            ->toBe([[null, -50], ['Holidays', -1_000], ['Work', -1_300]]);

        // Filtered by a label, only that label is listed.
        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['label'], 'label_ids' => [$holidays->id]]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['label_id' => $holidays->id, 'label_name' => 'Holidays', 'currency' => 'EUR', 'sum' => -1_000, 'count' => 1],
            ]);
    });

    it('groups by account and keeps every currency apart', function () {
        ['user' => $user, 'account' => $account] = aggregateBook();
        $dollars = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'USD', 'name' => 'Dollars']);
        aggregateRow($account, '2026-10-01', -1_000);
        aggregateRow($dollars, '2026-10-01', -700);
        aggregateRow($account, '2026-10-02', -300, null, ['currency_code' => 'USD']);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['currency' => 'EUR', 'sum' => -1_000, 'count' => 1],
                ['currency' => 'USD', 'sum' => -1_000, 'count' => 2],
            ]);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['account']]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [
                ['account_id' => $dollars->id, 'account_name' => 'Dollars', 'currency' => 'USD', 'sum' => -700, 'count' => 1],
                ['account_id' => $account->id, 'account_name' => 'Main', 'currency' => 'EUR', 'sum' => -1_000, 'count' => 1],
                ['account_id' => $account->id, 'account_name' => 'Main', 'currency' => 'USD', 'sum' => -300, 'count' => 1],
            ]);
    });

    it('counts the user\'s share of the account and stops counting an archived account from its archive day', function () {
        ['user' => $user] = aggregateBook();
        $shared = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'ownership_percentage' => 50]);
        $archived = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'archived_at' => '2026-10-10 08:00:00']);
        aggregateRow($shared, '2026-10-01', -1_001);
        aggregateRow($archived, '2026-10-09', -200);
        aggregateRow($archived, '2026-10-10', -9_999);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [['currency' => 'EUR', 'sum' => -701, 'count' => 2]]);
    });
});

describe('filters', function () {
    it('narrows by category, with or without its subcategories', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'groceries' => $groceries, 'salary' => $salary] = aggregateBook();
        aggregateRow($account, '2026-10-01', -1_000, $food);
        aggregateRow($account, '2026-10-02', -2_000, $groceries);
        aggregateRow($account, '2026-10-03', 50_000, $salary);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'category_ids' => [$food->id]]))
            ->assertOk()
            ->assertJsonPath('data.buckets.0.sum', -3_000);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'category_ids' => [$food->id], 'include_subcategories' => 'false']))
            ->assertOk()
            ->assertJsonPath('data.buckets.0.sum', -1_000);
    });

    it('narrows by date range, account, label, type and text', function (string $case, int $sum) {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = aggregateBook();
        $other = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $label = Label::factory()->create(['user_id' => $user->id]);
        aggregateRow($account, '2026-09-30', -1);
        aggregateRow($account, '2026-10-01', -20, null, ['description' => 'Coffee shop']);
        aggregateRow($other, '2026-10-02', -300)->labels()->attach($label->id);
        aggregateRow($account, '2026-10-03', 4_000, $salary);

        $filters = match ($case) {
            'range' => [],
            'account' => ['account_ids' => [$other->id]],
            'label' => ['label_ids' => [$label->id]],
            'type' => ['types' => ['income']],
            'text' => ['query' => 'coffee'],
        };

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', ...$filters]))
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

describe('limits and validation', function () {
    it('refuses more buckets than the cap with a clear error', function (array $query) {
        ['user' => $user, 'account' => $account, 'food' => $food, 'salary' => $salary] = aggregateBook();
        aggregateRow($account, '2026-01-01', -1_000, $food);
        aggregateRow($account, '2026-01-01', 1_000, $salary);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group_by' => 'more than 1000 buckets']);
    })->with([
        'too many days' => [['from' => '2020-01-01', 'to' => '2026-01-01', 'group_by' => ['day']]],
        'too many days per category' => [['from' => '2026-01-01', 'to' => '2027-06-30', 'group_by' => ['day', 'category']]],
    ]);

    it('refuses bad parameters', function (array $query, string $field) {
        ['user' => $user] = aggregateBook();

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', ...$query]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'two periods' => [['group_by' => ['day', 'month']], 'group_by'],
        'three dimensions' => [['group_by' => ['month', 'category', 'label']], 'group_by'],
        'unknown dimension' => [['group_by' => ['merchant']], 'group_by.0'],
        'unknown metric' => [['metrics' => ['median']], 'metrics.0'],
        'unknown type' => [['types' => ['refund']], 'types.0'],
        'reversed range' => [['to' => '2026-09-01'], 'to'],
    ]);
});

describe('permissions', function () {
    it('adds up only your own transactions of the space', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $owner = User::factory()->create(['currency_code' => 'EUR']);
        $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
        $space->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
        aggregateRow(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'currency_code' => 'EUR']), '2026-10-01', -100);
        aggregateRow(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'currency_code' => 'EUR']), '2026-10-01', -5_000);
        aggregateRow(Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']), '2026-10-01', -7);
        aggregateRow(Account::factory()->create(['user_id' => User::factory()->create()->id, 'currency_code' => 'EUR']), '2026-10-01', -9_000);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'space' => $space->id]))
            ->assertOk()
            ->assertJsonPath('data.buckets', [['currency' => 'EUR', 'sum' => -100, 'count' => 1]]);

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertJsonPath('data.buckets', [['currency' => 'EUR', 'sum' => -7, 'count' => 1]]);
    });

    it('refuses an account, category or label that is not yours in the space', function (string $field) {
        ['user' => $user] = aggregateBook();
        $owner = User::factory()->create();
        $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
        $space->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
        $theirs = match ($field) {
            'account_ids' => Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]),
            'category_ids' => Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]),
            'label_ids' => Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]),
        };

        withHeaders(apiV1Bearer($user))
            ->getJson(aggregateUrl(['from' => '2026-10-01', 'to' => '2026-10-31', 'space' => $space->id, $field => [$theirs->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors("{$field}.0");
    })->with(['account_ids', 'category_ids', 'label_ids']);
});
