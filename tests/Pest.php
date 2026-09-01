<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\EnableBankingProvider;
use App\Services\Banking\Sync\BankingConnectionSyncerFactory;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Stripe\Collection as StripeCollection;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Tests\TestCase;

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

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser', 'Performance');

pest()->browser()->timeout(15000);

/*
|--------------------------------------------------------------------------
| Transient AI provider failures
|--------------------------------------------------------------------------
|
| The two ways an AI provider fails with nobody having a bug to fix: it answers
| badly (overloaded, rate-limiting us) or it does not answer at all (DNS, connect
| timeout). Every service that prompts a provider has to treat the two the same
| way, so they assert against one shared list rather than each keeping its own.
|
*/

dataset('transient provider failures', [
    'provider overloaded' => fn (): Throwable => ProviderOverloadedException::forProvider('gemini'),
    'provider unreachable' => fn (): Throwable => new ConnectionException(
        'cURL error 6: Could not resolve host: generativelanguage.googleapis.com',
    ),
]);

/*
|--------------------------------------------------------------------------
| Disable Vite globally for non-browser suites
|--------------------------------------------------------------------------
|
| Feature/Performance suites render Inertia pages but never assert on
| the JS bundle. Calling withoutVite() here removes the dependency on a
| compiled Vite manifest, so these jobs no longer need the build-assets
| artifact in CI. Browser tests still get a real manifest because the
| dev server / built assets are required to drive Playwright.
*/
pest()->beforeEach(function () {
    $this->withoutVite();
})->in('Feature', 'Performance');

/*
|--------------------------------------------------------------------------
| Block stray HTTP requests in Feature tests
|--------------------------------------------------------------------------
|
| Any Feature test whose code path hits the network without a matching
| Http::fake() should fail loudly instead of making a real request. Tests
| that legitimately talk to external services register their own fakes,
| which take precedence over this guard.
*/
pest()->beforeEach(function () {
    Http::preventStrayRequests();
})->in('Feature');

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
function performanceSeedUser(): User
{
    $user = User::factory()->onboarded()->create();

    $categories = Category::factory(5)->create(['user_id' => $user->id]);
    Label::factory(3)->create(['user_id' => $user->id]);

    $accounts = Account::factory(3)->create([
        'user_id' => $user->id,
        'currency_code' => $user->currency_code,
    ]);

    foreach ($accounts as $index => $account) {
        Transaction::factory(10)->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $categories->random()->id,
            'currency_code' => $user->currency_code,
        ]);

        for ($i = 0; $i < 5; $i++) {
            AccountBalance::factory()->create([
                'account_id' => $account->id,
                'balance_date' => now()->subDays(($index * 5) + $i + 1)->toDateString(),
            ]);
        }
    }

    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
    BudgetPeriod::factory()->create([
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
function countQueries(Closure $callback): array
{
    $queryLog = [];

    DB::listen(function ($query) use (&$queryLog) {
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
function assertMaxQueries(int $max, Closure $callback, string $context = ''): void
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

/**
 * Build a fake Stripe subscription object for stats tests.
 */
function makeStripeSubscription(string $currency, int $unitAmount, string $interval, int $quantity = 1, int $intervalCount = 1): Subscription
{
    return Subscription::constructFrom([
        'object' => 'subscription',
        'currency' => $currency,
        'items' => [
            'object' => 'list',
            'data' => [
                [
                    'object' => 'subscription_item',
                    'quantity' => $quantity,
                    'price' => [
                        'object' => 'price',
                        'unit_amount' => $unitAmount,
                        'recurring' => [
                            'interval' => $interval,
                            'interval_count' => $intervalCount,
                        ],
                    ],
                ],
            ],
        ],
    ]);
}

/**
 * @param  list<Subscription>  $subscriptions
 */
function makeSubscriptionCollection(array $subscriptions): StripeCollection
{
    return StripeCollection::constructFrom([
        'object' => 'list',
        'has_more' => false,
        'data' => array_map(fn (Subscription $s) => $s->toArray(), $subscriptions),
    ]);
}

/**
 * Bind a mocked Stripe client that returns subscriptions per status.
 *
 * @param  array<string, list<Subscription>>  $byStatus
 */
function bindMockStripeClientForStats(array $byStatus): void
{
    $subscriptionService = Mockery::mock(SubscriptionService::class);

    $subscriptionService->shouldReceive('all')
        ->andReturnUsing(function (array $params) use ($byStatus): StripeCollection {
            $status = $params['status'] ?? 'active';

            return makeSubscriptionCollection($byStatus[$status] ?? []);
        });

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->subscriptions = $subscriptionService;

    app()->bind(StripeClient::class, fn () => $stripeClient);
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
        ->waitForText('Manual', 5)
        ->click('Manual')
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

/**
 * An EnableBankingProvider signing with a throwaway key, for tests that drive
 * the real provider against a faked HTTP layer.
 */
function enableBankingProviderForTest(): EnableBankingProvider
{
    // Generated once per process rather than committed: a working RSA key in a
    // public repo is a bad pattern to keep, and the signature is never verified
    // by anything but the faked HTTP layer.
    static $path = null;

    if ($path === null) {
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $privateKey);

        $path = tempnam(sys_get_temp_dir(), 'enablebanking-test-key');
        file_put_contents($path, $privateKey);
    }

    return new EnableBankingProvider('test-app-id', $path);
}

/**
 * Run the banking sync job through the real syncer factory, binding any
 * EnableBanking sync-service mocks the test provides so the resolved syncer
 * uses them instead of the container defaults.
 */
function runSync(
    SyncBankingConnectionJob $job,
    ?object $transactionSync = null,
    ?object $balanceSync = null,
): void {
    if ($transactionSync !== null) {
        app()->instance(TransactionSyncService::class, $transactionSync);
    }

    if ($balanceSync !== null) {
        app()->instance(BalanceSyncService::class, $balanceSync);
    }

    $job->handle(app(BankingConnectionSyncerFactory::class));
}

/**
 * A connection whose accounts the bank serves unevenly. Production had a CaixaBank
 * one in this shape for five weeks: account 1 importing transactions while accounts
 * 2 and 3 sat frozen at the date of the last complete run.
 */
function enableBankingConnectionWithAccounts(int $count): BankingConnection
{
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => now()->subDay(),
        'consecutive_sync_failures' => 0,
    ]);

    for ($i = 1; $i <= $count; $i++) {
        Account::factory()->connected()->create([
            'user_id' => $user->id,
            'banking_connection_id' => $connection->id,
            'external_account_id' => "ext-{$i}",
        ]);
    }

    return $connection;
}

function finalAttemptJobFor(BankingConnection $connection): SyncBankingConnectionJob
{
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    return $job;
}

/**
 * Two accounts either side of the archive cutoff, for the surfaces that must
 * stop counting an archived account from the day it was archived.
 *
 * One account is archived mid-May at 10:30 — a time of day, so a cutoff that
 * compares the raw timestamp instead of the day is caught — and the other stays
 * live. Both get the same three expenses: the day before the archive date, the
 * day of, and the day after. So of the archived account's 7000 only the first
 * 1000 may ever count, while the live account keeps all 7000, for 8000 in total
 * over May 2026.
 *
 * Currencies are pinned to the user's so no exchange rate is involved.
 *
 * @return array{archived: Account, active: Account}
 */
function accountsAcrossArchiveCutoff(User $user, Category $category): array
{
    $accounts = [
        'archived' => Account::factory()->create([
            'user_id' => $user->id,
            'currency_code' => $user->currency_code,
            'archived_at' => '2026-05-15 10:30:00',
        ]),
        'active' => Account::factory()->create([
            'user_id' => $user->id,
            'currency_code' => $user->currency_code,
            'archived_at' => null,
        ]),
    ];

    foreach ($accounts as $account) {
        foreach (['2026-05-14' => -1000, '2026-05-15' => -2000, '2026-05-16' => -4000] as $date => $amount) {
            Transaction::factory()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'category_id' => $category->id,
                'currency_code' => $user->currency_code,
                'amount' => $amount,
                'transaction_date' => $date,
            ]);
        }
    }

    return $accounts;
}

/**
 * Fake the external currency-rate provider (the jsdelivr CDN and its pages.dev
 * fallback) that CurrencyConversionService and ExchangeRateService fetch from,
 * returning a deterministic 1:1 rate for every currency. Tests that trigger a
 * currency conversion (e.g. the balance-evolution endpoint) can call this in
 * their setup to stay hermetic under the stray-request guard.
 *
 * The stub only matches the provider's `/currencies/{code}.min.json` path and
 * returns null otherwise, so it never shadows another test's own Http::fake()
 * nor the stray-request guard for unrelated hosts.
 */
function fakeCurrencyApi(): void
{
    Http::fake(function (Request $request) {
        if (! preg_match('#/currencies/([a-z0-9]+)\.min\.json#i', $request->url(), $matches)) {
            return null;
        }

        $currency = strtolower($matches[1]);

        return Http::response([
            'date' => '2024-01-01',
            $currency => [
                'usd' => 1.0,
                'eur' => 1.0,
                'gbp' => 1.0,
                'jpy' => 1.0,
                'btc' => 1.0,
                'eth' => 1.0,
            ],
        ]);
    });
}
