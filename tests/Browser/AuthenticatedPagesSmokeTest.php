<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Achievement;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\MonthlySummary;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\CardRenderer;

use function Pest\Laravel\actingAs;

/**
 * Every screen a signed-in reader can reach, opened with data behind it.
 *
 * A page that throws on render answers 200 all the same, so the HTTP tests next
 * door cannot see it. This walks the list, asserts on something only that page
 * prints, and fails on the first JavaScript error. The phone-width run is here
 * because several of the regressions it would have caught were mobile-only.
 *
 * `/settings/banks` is not in the list: it answers JSON, not a page.
 */
beforeEach(function (): void {
    // The suite turns the paywall off for every test (tests/TestCase.php), and
    // with it off both `/settings/billing` and `/subscribe` bounce to the
    // dashboard. They are on the list, so it goes back on and the reader below
    // pays for a plan.
    config(['subscriptions.enabled' => true]);

    // The share card the summary screens ask for is drawn by a headless browser
    // in production; the URL is all these assertions need.
    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('url')->andReturn('https://whisper.money/storage/card.png');
        $mock->shouldReceive('forget')->andReturnNull();
    });

    fakeCurrencyApi();
});

/**
 * One reader with a little of everything, so no screen lands on its empty state.
 *
 * Subscribed, because the paywall is on for these tests: everything behind it
 * has to open, and `/settings/billing` only shows the plan to someone who has
 * one.
 */
function seedSmokeReader(): User
{
    $user = User::factory()->onboarded()->subscribed()->create([
        'currency_code' => 'USD',
        'locale' => 'en',
    ]);

    $bank = Bank::factory()->create(['name' => 'Smoke Bank']);

    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Smoke Checking',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonthsNoOverflow(2)->toDateString(),
        'balance' => 180000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 250000,
    ]);

    $groceries = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Smoke Groceries',
        'type' => CategoryType::Expense,
    ]);
    $salary = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Smoke Salary',
        'type' => CategoryType::Income,
    ]);

    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Smoke Label']);

    foreach ([['Smoke weekly shop', -4210, $groceries->id], ['Smoke payday', 300000, $salary->id]] as [$description, $amount, $categoryId]) {
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $categoryId,
            'description' => $description,
            'amount' => $amount,
            'currency_code' => 'USD',
            'transaction_date' => now()->toDateString(),
        ]);

        $transaction->labels()->attach($label->id);
    }

    // The one row the categorize screen exists for.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'Smoke uncategorized coffee',
        'amount' => -420,
        'currency_code' => 'USD',
        'transaction_date' => now()->toDateString(),
    ]);

    $budget = Budget::factory()->monthly()->forCategories($groceries)->create([
        'user_id' => $user->id,
        'name' => 'Smoke Budget',
        'period_start_day' => 1,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
    ]);

    SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'name' => 'Smoke Goal',
        'target_amount' => 500000,
    ]);

    MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'period' => now()->subMonthNoOverflow()->format('Y-m'),
    ]);

    Achievement::factory()->key('net_worth.2')->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'currency_code' => 'USD',
    ]);

    BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Smoke Connected Bank',
    ]);

    AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Smoke Rule',
        'action_category_id' => $groceries->id,
    ]);

    return $user;
}

/**
 * Fill the model ids into a path from the dataset.
 */
function smokePagePath(string $path, User $user): string
{
    return str_replace(
        ['{account}', '{budget}', '{savingsGoal}', '{summary}'],
        [
            $user->accounts()->sole()->id,
            $user->budgets()->sole()->id,
            $user->savingsGoals()->sole()->id,
            $user->monthlySummaries()->sole()->id,
        ],
        $path,
    );
}

dataset('authenticated pages (smoke)', [
    'dashboard' => ['/dashboard', 'Overview of your financial health'],
    'transactions' => ['/transactions', 'View and manage your transactions'],
    'categorize' => ['/transactions/categorize', 'Smoke uncategorized coffee'],
    'accounts' => ['/accounts', 'View and manage your bank accounts'],
    'account' => ['/accounts/{account}', 'Smoke Checking'],
    'budgets' => ['/budgets', 'Planning'],
    'budget' => ['/budgets/{budget}', 'Smoke Budget'],
    'savings goal' => ['/savings-goals/{savingsGoal}', 'Smoke Goal'],
    'cashflow' => ['/cashflow', 'Track your income, expenses, and savings'],
    'summaries' => ['/summaries', 'Monthly summaries'],
    'summary' => ['/summaries/{summary}', 'The figures'],
    'progress' => ['/progress', 'Every milestone your money has crossed'],
    'notifications' => ['/notifications', 'Everything that happened in your account'],
    'profile settings' => ['/settings/profile', 'Profile information'],
    'account settings' => ['/settings/account', 'Profile information'],
    'bank account settings' => ['/settings/accounts', 'Bank accounts'],
    'appearance settings' => ['/settings/appearance', 'Appearance settings'],
    'automation rule settings' => ['/settings/automation-rules', 'Automation rules settings'],
    'billing settings' => ['/settings/billing', 'Your Pro Plan'],
    'category settings' => ['/settings/categories', 'Categories settings'],
    'connection settings' => ['/settings/connections', 'Bank Connections'],
    'label settings' => ['/settings/labels', 'Labels settings'],
    'mcp settings' => ['/settings/mcp', 'AI Connector'],
    'notification settings' => ['/settings/notifications', 'Email notifications'],
    'password settings' => ['/settings/password', 'Update password'],
    'delete account settings' => ['/settings/delete-account', 'Delete account'],
]);

it('opens every authenticated page', function (string $path, string $expected): void {
    $user = seedSmokeReader();

    actingAs($user);

    visit(smokePagePath($path, $user))
        ->assertSee($expected)
        ->assertNoJavascriptErrors();
})->with('authenticated pages (smoke)');

it('opens every authenticated page at phone width', function (string $path, string $expected): void {
    $user = seedSmokeReader();

    actingAs($user);

    visit(smokePagePath($path, $user))
        ->resize(390, 844)
        ->assertSee($expected)
        ->assertNoJavascriptErrors();
})->with('authenticated pages (smoke)');

it('opens the two-factor page once the password is confirmed', function (): void {
    // Not on the list above: the route asks for the password again first, which
    // is a screen of its own rather than a heading to assert.
    $user = seedSmokeReader();

    actingAs($user);

    visit('/settings/two-factor')
        ->assertSee('Confirm your password')
        ->fill('password', 'password')
        ->click('[data-test="confirm-password-button"]')
        ->assertSee('Two-Factor Authentication')
        ->assertSee('Manage your two-factor authentication settings')
        ->assertNoJavascriptErrors();
});

it('opens the paywall for a reader without a plan', function (): void {
    // Its own test: the paywall is the one screen a subscriber never sees, so it
    // cannot ride the list above.
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD', 'locale' => 'en']);

    actingAs($user);

    visit('/subscribe')
        ->assertSee('One thing left to decide')
        ->assertNoJavascriptErrors();
});
