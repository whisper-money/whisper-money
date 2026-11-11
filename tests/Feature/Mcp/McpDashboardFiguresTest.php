<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCreditCardUsage;
use App\Mcp\Tools\GetMonthlySavings;
use App\Mcp\Tools\SpendingByLabel;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
});

/**
 * One expense of $amount on a fresh account of $user, booked on 3 October.
 */
function mcpFiguresExpense(User $user, int $amount, ?Account $account = null): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => ($account ?? Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']))->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense])->id,
        'amount' => -$amount,
        'currency_code' => 'EUR',
        'transaction_date' => '2026-10-03',
    ]);
}

it('ranks spending by label with formatted amounts, the caller\'s own only', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $trip = Label::factory()->create(['user_id' => $user->id, 'name' => 'Trip']);
    mcpFiguresExpense($user, 219_888)->labels()->attach($trip->id);
    $stranger = User::factory()->create();
    mcpFiguresExpense($stranger, 9_999)->labels()->attach(Label::factory()->create(['user_id' => $stranger->id])->id);

    WhisperMoneyServer::actingAs($user)
        ->tool(SpendingByLabel::class, ['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->assertOk()
        ->assertSee('"currency":"EUR","previous_from":"2026-09-01","previous_to":"2026-09-30"')
        ->assertSee('{"label_id":"'.$trip->id.'","name":"Trip","color":"'.$trip->color.'","amount":219888,"amount_formatted":"€2,198.88","previous_amount":0}')
        ->assertDontSee('9999');
});

it('needs a range for spending by label', function () {
    WhisperMoneyServer::actingAs(User::factory()->create())
        ->tool(SpendingByLabel::class, ['from' => '2026-10-01'])
        ->assertHasErrors();
});

it('gives the credit card usage the API gives, the caller\'s own cards only', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id, 'name' => 'Visa', 'currency_code' => 'EUR']);
    CreditCardDetail::factory()->create(['account_id' => $card->id, 'statement_closing_date' => null, 'payment_due_date' => null, 'credit_limit' => 300_000]);
    mcpFiguresExpense($user, 124_000, $card);
    Account::factory()->creditCard()->create(['user_id' => User::factory()->create()->id, 'name' => 'Not yours']);

    $api = $this->withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/credit-card-usage')->json('data.0');

    WhisperMoneyServer::actingAs($user)
        ->tool(GetCreditCardUsage::class)
        ->assertOk()
        ->assertSee((string) json_encode(['credit_cards' => [[...$api, 'used_formatted' => '€1,240.00']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->assertDontSee('Not yours');
});

it('gives this month\'s savings the API gives, the caller\'s own goals only', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id, 'name' => 'Fund', 'created_at' => '2026-08-15']);
    app(SavingsGoalPeriodService::class)->advance($goal);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings])->id,
        'amount' => 25_000,
        'transaction_date' => '2026-10-02',
    ])->labels()->attach($goal->label_id);
    SavingsGoal::factory()->monthly()->create(['user_id' => User::factory()->create()->id, 'name' => 'Not yours']);

    $api = $this->withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/monthly-savings')->json('data');

    WhisperMoneyServer::actingAs($user)
        ->tool(GetMonthlySavings::class)
        ->assertOk()
        ->assertSee((string) json_encode([...$api, 'saved_formatted' => '€250.00', 'target_formatted' => '€300.00'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->assertDontSee('Not yours');
});
