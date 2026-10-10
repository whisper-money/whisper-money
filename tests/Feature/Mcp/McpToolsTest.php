<?php

use App\Enums\CategoryType;
use App\Features\CreditCardStatements;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListAchievements;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use App\Models\Account;
use App\Models\Achievement;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

it('blocks read tools when subscriptions are enabled and the user has no paid plan', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListSpaces::class)
        ->assertHasErrors()
        ->assertSee('Pro');
});

it('allows read tools for a user on a paid plan', function () {
    // subscriptions disabled => everyone is treated as Pro (hasProPlan()).
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListSpaces::class)
        ->assertOk()
        ->assertSee('Personal');
});

it('searches transactions scoped to the user\'s space', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Blue Bottle Coffee',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['query' => 'Blue Bottle'])
        ->assertOk()
        ->assertSee('Blue Bottle Coffee');
});

it('returns the notes of a transaction so they can be read back', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Hardware store',
        'notes' => 'Shelves for the office',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['query' => 'Hardware store'])
        ->assertOk()
        ->assertSee('Shelves for the office');
});

it('filters transactions by label id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $labelled = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Labelled Lunch',
    ]);
    $labelled->labels()->attach($label->id);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Unlabelled Dinner',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['label_ids' => [$label->id]])
        ->assertOk()
        ->assertSee('Labelled Lunch')
        ->assertDontSee('Unlabelled Dinner');
});

it('rejects a label id the user cannot access', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreignLabel = Label::factory()->create(['user_id' => $other->id]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['label_ids' => [$foreignLabel->id]])
        ->assertHasErrors();
});

it('never exposes another user\'s transactions', function () {
    $user = User::factory()->create();
    $userAccount = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $userAccount->id,
        'description' => 'My Own Groceries',
    ]);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $otherAccount->id,
        'description' => 'Secret Steakhouse',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, [])
        ->assertOk()
        ->assertSee('My Own Groceries')
        ->assertDontSee('Secret Steakhouse');
});

it('rejects a space id the user cannot access', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, ['space' => $other->personalSpace->id])
        ->assertHasErrors();
});

it('lists the user\'s accounts for the space', function () {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Everyday Checking']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertSee('Everyday Checking');
});

it('presents the next credit card payment when the user has the feature', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    Feature::for($user)->activate(CreditCardStatements::class);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', 'UTC'));

    $cards = Account::factory()->creditCard()->count(3)->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $cards->each(fn (Account $card) => CreditCardDetail::factory()->create([
        'account_id' => $card->id,
        'statement_closing_date' => '2026-03-05',
        'payment_due_date' => '2026-03-20',
    ]));
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $cards->first()->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense])->id,
        'currency_code' => 'EUR',
        'amount' => -4321,
        'transaction_date' => '2026-03-01',
    ]);

    DB::enableQueryLog();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertSee('credit_card_statement')
        ->assertSee('"amount":4321')
        ->assertSee('"is_final":true')
        ->assertSee('2026-03-20');

    // One ledger query per card on top of the fixed ones, never one per row.
    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from `transactions`'))->count())->toBe(3);
});

it('presents no credit card estimate to a user without the feature', function () {
    $user = User::factory()->create();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
    CreditCardDetail::factory()->create(['account_id' => $card->id]);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertDontSee('credit_card_statement')
        ->assertDontSee('credit_card_detail');
});

it('lists the user\'s categories for the space', function () {
    $user = User::factory()->create();
    Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListCategories::class, [])
        ->assertOk()
        ->assertSee('Groceries');
});

it('lists budgets with what the current period has spent and has left', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Food Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
    ]);

    $budget->periods()->create([
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 50_000,
        'carried_over_amount' => 0,
    ]);

    // Assigned to the period by the TransactionCreated listener.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'transaction_date' => today(),
        'amount' => -12_000,
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertSee('Food Budget')
        ->assertSee('Groceries')
        ->assertSee('"allocated_amount":50000')
        ->assertSee('"spent_amount":12000')
        ->assertSee('"remaining_amount":38000');
});

it('says which currency the rolled-up figures are in', function (string $tool) {
    // The amounts alone cannot tell euros from dollars, and without this the
    // agent answered a euro account's spending with a dollar sign.
    $user = User::factory()->create(['currency_code' => 'EUR']);

    WhisperMoneyServer::actingAs($user)
        ->tool($tool, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])
        ->assertOk()
        ->assertSee('"currency":"EUR"');
})->with([
    'spending_by_category' => SpendingByCategory::class,
    'get_cashflow' => GetCashflow::class,
    'get_net_worth' => GetNetWorth::class,
]);

it('formats the spending amounts in the user\'s currency', function () {
    // Given only minor units and a currency, ChatGPT once scaled €2,198.88
    // down to €219.89; the formatted amount spares it the maths.
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries']);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => -219888,
        'currency_code' => 'EUR',
        'transaction_date' => now()->toDateString(),
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SpendingByCategory::class, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])
        ->assertOk()
        ->assertSee('"amount":219888')
        ->assertSee('"amount_formatted":"€2,198.88"');
});

it('tells the agent on the accounts tool that nothing moves money', function () {
    // ChatGPT reads tool descriptions more reliably than the server
    // instructions: asked to "transfer 500 euros to my mum's account", it
    // offered to do it once it knew the destination account.
    expect((new ListAccounts)->description())->toContain('no tool moves money');
});

it('reports the remaining amount the app shows, ignoring carry-over', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'rollover_type' => 'carry_over',
    ]);

    $budget->periods()->create([
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 50_000,
        'carried_over_amount' => 10_000,
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'transaction_date' => today(),
        'amount' => -12_000,
    ]);

    // The budget cards, the spending chart and the limit emails all measure
    // against the allocated amount alone, so the agent must not add carry-over.
    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertSee('"carried_over_amount":10000')
        ->assertSee('"remaining_amount":38000');
});

it('leaves archived budgets out of the list', function () {
    $user = User::factory()->create();
    Budget::factory()->create(['user_id' => $user->id, 'name' => 'Running Budget']);
    Budget::factory()->archived()->create(['user_id' => $user->id, 'name' => 'Archived Budget']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertSee('Running Budget')
        ->assertDontSee('Archived Budget');
});

it('never exposes another user\'s budgets', function () {
    $user = User::factory()->create();
    Budget::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Secret Budget']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertDontSee('Secret Budget');
});

it('lists the user\'s automation rules with their actions', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Essentials']);

    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Supermarket rule',
        'priority' => 3,
        'action_category_id' => $category->id,
    ]);
    $rule->labels()->attach($label->id);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAutomationRules::class)
        ->assertOk()
        ->assertSee(['Supermarket rule', '"priority":3', $category->id, 'Essentials']);
});

it('never exposes another user\'s automation rules', function () {
    $user = User::factory()->create();
    AutomationRule::factory()->create([
        'user_id' => User::factory()->create()->id,
        'title' => 'Secret Rule',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAutomationRules::class)
        ->assertOk()
        ->assertDontSee('Secret Rule');
});

/*
 * Medals. The tool hands over the same payload the progress screen renders, so
 * a medal still to come stays a silhouette here too.
 */

function medalist(string ...$keys): User
{
    $user = User::factory()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    foreach ($keys as $key) {
        Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
        ]);
    }

    return $user;
}

it('lists the medals a reader has earned alongside the ones still to come', function () {
    WhisperMoneyServer::actingAs(medalist('transactions.1', 'net_worth.1'))
        ->tool(ListAchievements::class)
        ->assertOk()
        ->assertSee(['First transaction', '"unlocked":2', '"total":59']);
});

it('names the next rung of a track and keeps the ones past it to itself', function () {
    WhisperMoneyServer::actingAs(medalist('transactions.1'))
        ->tool(ListAchievements::class)
        ->assertOk()
        // transactions.2 is the rung to aim at, so it arrives named and with a
        // bar to fill. safety.2 sits behind safety.1 and stays a silhouette.
        ->assertSee(['"state":"next"', 'Transactions recorded', '"goal":50'])
        ->assertSee('"state":"locked","name":null,"icon":null,"figure":null')
        ->assertDontSee('Emergency fund');
});

it('never exposes another user\'s medals', function () {
    medalist('transactions.1');

    WhisperMoneyServer::actingAs(User::factory()->create())
        ->tool(ListAchievements::class)
        ->assertOk()
        // Not a name: the first rung of every track is revealed to everybody,
        // so what must not appear is a medal anyone actually holds.
        ->assertSee('"unlocked":0')
        ->assertDontSee('"state":"earned"');
});
