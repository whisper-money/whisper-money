<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Browser', 'Performance');

pest()->browser()->timeout(15000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a user with realistic data for performance testing.
 *
 * Includes 3 accounts, 30 transactions, 15 balances, 5 categories,
 * 3 labels, and 1 budget with a current period.
 */
function performanceSeedUser(): App\Models\User
{
    $user = App\Models\User::factory()->onboarded()->create();

    $categories = App\Models\Category::factory(5)->create(['user_id' => $user->id]);
    App\Models\Label::factory(3)->create(['user_id' => $user->id]);

    $accounts = App\Models\Account::factory(3)->create(['user_id' => $user->id]);

    foreach ($accounts as $index => $account) {
        App\Models\Transaction::factory(10)->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $categories->random()->id,
        ]);

        for ($i = 0; $i < 5; $i++) {
            App\Models\AccountBalance::factory()->create([
                'account_id' => $account->id,
                'balance_date' => now()->subDays(($index * 5) + $i + 1)->toDateString(),
            ]);
        }
    }

    $budget = App\Models\Budget::factory()->monthly()->create(['user_id' => $user->id]);
    App\Models\BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
    ]);

    return $user;
}

/**
 * Count the number of database queries executed by the given callback.
 *
 * @return array{count: int, queries: list<string>}
 */
function countQueries(\Closure $callback): array
{
    $queryLog = [];

    Illuminate\Support\Facades\DB::listen(function ($query) use (&$queryLog) {
        $queryLog[] = $query->sql;
    });

    $callback();

    return ['count' => count($queryLog), 'queries' => $queryLog];
}

/**
 * Assert that the callback executes at most $max database queries.
 *
 * On failure, dumps all executed queries for easy debugging.
 */
function assertMaxQueries(int $max, \Closure $callback, string $context = ''): void
{
    $result = countQueries($callback);

    if ($result['count'] > $max) {
        $message = "{$context}: Expected at most {$max} queries, but {$result['count']} were executed.\n\nQueries:\n";
        foreach ($result['queries'] as $i => $sql) {
            $message .= sprintf("  %d. %s\n", $i + 1, $sql);
        }
        test()->fail($message);
    }

    expect($result['count'])->toBeLessThanOrEqual($max);
}

function createCategoryViaUI($page, string $name, string $color = 'green', string $type = 'Expense'): void
{
    $page->click('Create Category')
        ->wait(0.5)
        ->fill('name', $name)
        ->click('Select an icon')
        ->wait(0.5)
        ->click('//div[@role="option"][1]')
        ->wait(0.3)
        ->click('Select a color')
        ->wait(0.5)
        ->click("//div[@role=\"option\"][contains(., \"{$color}\")]")
        ->wait(0.3)
        ->click('Select a type')
        ->wait(0.5)
        ->click("//div[@role=\"option\"][contains(., \"{$type}\")]")
        ->wait(0.3)
        ->click('button[type="submit"]')
        ->wait(2);
}

function createAccountViaUI($page, string $displayName, string $bankName, string $type = 'Checking', string $currency = 'USD'): void
{
    $page->assertSee('Bank accounts');
    $page->click('Create Account')
        ->wait(0.5)
        ->fill('#display_name', $displayName)
        ->click('[data-testid="bank-select"]')
        ->wait(0.5)
        ->fill('input[placeholder="Search bank..."]', $bankName)
        ->wait(0.5)
        ->click($bankName)
        ->click('button[name="type"]')
        ->wait(0.5)
        ->click("[role=\"option\"]:has-text(\"{$type}\")")
        ->wait(0.3)
        ->click('button[name="currency_code"]')
        ->wait(0.5)
        ->click("[role=\"option\"]:has-text(\"{$currency}\")")
        ->wait(0.3)
        ->click('[data-testid="submit-account"]')
        ->wait(2);
}
