<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Features\CreditCardStatements;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CreditCards\CreditCardStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    Http::fake();

    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->card = Account::factory()->creditCard()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);
    $this->expense = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $this->transfer = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer]);

    // Statement closes on the 5th and is charged on the 20th.
    $this->detail = CreditCardDetail::factory()->create([
        'account_id' => $this->card->id,
        'statement_closing_date' => '2026-03-05',
        'payment_due_date' => '2026-03-20',
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function cardTransaction(int $amount, string $date, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->card->id,
        'category_id' => test()->expense->id,
        'currency_code' => 'EUR',
        'amount' => $amount,
        'transaction_date' => $date,
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function estimateOn(string $today): array
{
    return figuresOn($today)['credit_card_statement'];
}

/**
 * @return array<string, mixed>
 */
function figuresOn(string $today): array
{
    return app(CreditCardStatementService::class)->figuresOn(test()->card->load('creditCardDetail'), CarbonImmutable::parse($today));
}

// -------------------------------------------------------------------
// Amounts
// -------------------------------------------------------------------

it('adds up the closed statement and the open cycle separately', function () {
    cardTransaction(-1000, '2026-02-06');
    cardTransaction(-2000, '2026-03-05');
    cardTransaction(-4000, '2026-03-06');
    cardTransaction(-8000, '2026-02-05');

    $estimate = estimateOn('2026-03-10');

    expect($estimate['next_payment'])->toMatchArray([
        'period_from' => '2026-02-06',
        'closing_date' => '2026-03-05',
        'due_date' => '2026-03-20',
        'amount' => 3000,
        'is_final' => true,
    ])->and($estimate['current_cycle'])->toMatchArray([
        'period_from' => '2026-03-06',
        'closing_date' => '2026-04-05',
        'due_date' => '2026-04-20',
        'amount' => 4000,
    ]);
});

it('charges the open cycle as a provisional amount once the due date has passed', function () {
    cardTransaction(-1000, '2026-03-01');
    cardTransaction(-2500, '2026-03-15');

    expect(estimateOn('2026-03-21')['next_payment'])->toMatchArray([
        'closing_date' => '2026-04-05',
        'due_date' => '2026-04-20',
        'amount' => 2500,
        'is_final' => false,
    ]);
});

it('subtracts refunds', function () {
    cardTransaction(-5000, '2026-03-07');
    cardTransaction(1500, '2026-03-08');

    expect(estimateOn('2026-03-10')['current_cycle']['amount'])->toBe(3500);
});

it('leaves out repayments booked to a transfer category and uncategorized inflows', function () {
    cardTransaction(-5000, '2026-03-07');
    cardTransaction(20000, '2026-03-08', ['category_id' => test()->transfer->id]);
    cardTransaction(-300, '2026-03-08', ['category_id' => test()->transfer->id]);
    cardTransaction(9000, '2026-03-09', ['category_id' => null]);
    cardTransaction(-700, '2026-03-09', ['category_id' => null]);

    expect(estimateOn('2026-03-10')['current_cycle']['amount'])->toBe(5700);
});

it('counts the parts of a split once, not the original', function () {
    $original = cardTransaction(-6000, '2026-03-07');
    cardTransaction(-4000, '2026-03-07', ['split_parent_id' => $original->id]);
    cardTransaction(-2000, '2026-03-07', ['split_parent_id' => $original->id]);
    $original->delete();

    expect(estimateOn('2026-03-10')['current_cycle']['amount'])->toBe(6000);
});

it('reads the date the bank booked a row, not the one the user moved it to', function () {
    // Booked by the bank inside the closed statement, moved by the user into
    // the open cycle for their cashflow.
    cardTransaction(-1200, '2026-03-12', ['source_date' => '2026-03-01']);

    $estimate = estimateOn('2026-03-10');

    expect($estimate['next_payment']['amount'])->toBe(1200)
        ->and($estimate['current_cycle']['amount'])->toBe(0);
});

it('charges the whole amount whatever share of the card the user owns', function () {
    test()->card->update(['ownership_percentage' => 50]);
    cardTransaction(-1000, '2026-03-07');

    expect(estimateOn('2026-03-10')['current_cycle']['amount'])->toBe(1000);
});

it('converts rows in another currency to the card currency', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2026-03-07',
        'rates' => ['usd' => 1.25],
    ]);

    cardTransaction(-10000, '2026-03-07', ['currency_code' => 'USD']);
    cardTransaction(-1000, '2026-03-07');

    // 100 USD at 1.25 USD per EUR is 80 EUR.
    expect(estimateOn('2026-03-10')['current_cycle']['amount'])->toBe(9000);
});

it('ignores other accounts', function () {
    $other = Account::factory()->creditCard()->create(['user_id' => test()->user->id, 'currency_code' => 'EUR']);
    cardTransaction(-1000, '2026-03-07', ['account_id' => $other->id]);

    expect(estimateOn('2026-03-10')['current_cycle']['amount'])->toBe(0);
});

// -------------------------------------------------------------------
// Credit usage
// -------------------------------------------------------------------

it('counts the statement still to be charged and the open cycle as used', function () {
    test()->detail->update(['credit_limit' => 10000]);
    cardTransaction(-8000, '2026-02-05');
    cardTransaction(-1000, '2026-02-06');
    cardTransaction(-2000, '2026-03-05');
    cardTransaction(-4000, '2026-03-06');
    cardTransaction(-500, '2026-03-12');

    $usage = figuresOn('2026-03-10')['credit_card_usage'];

    expect($usage)->toMatchArray([
        'limit' => 10000,
        'used' => 7000,
        'available' => 3000,
        'period_from' => '2026-02-06',
        'period_to' => '2026-04-05',
    ])->and($usage['daily'])->toHaveCount(33)
        ->and($usage['daily'][0])->toBe(['date' => '2026-02-06', 'used' => 1000])
        ->and($usage['daily'][27])->toBe(['date' => '2026-03-05', 'used' => 3000])
        ->and($usage['daily'][32])->toBe(['date' => '2026-03-10', 'used' => 7000]);
});

it('counts only the open cycle once the last statement has been charged', function () {
    cardTransaction(-1000, '2026-03-01');
    cardTransaction(-2500, '2026-03-15');

    expect(figuresOn('2026-03-21')['credit_card_usage'])->toMatchArray([
        'used' => 2500,
        'period_from' => '2026-03-06',
        'period_to' => '2026-04-05',
    ]);
});

it('counts the calendar month as used on a card without statement dates', function () {
    test()->detail->update(['statement_closing_date' => null, 'payment_due_date' => null, 'credit_limit' => 1000]);
    cardTransaction(-1000, '2026-02-28');
    cardTransaction(-3000, '2026-03-02');
    cardTransaction(500, '2026-03-03');
    cardTransaction(20000, '2026-03-04', ['category_id' => test()->transfer->id]);

    $figures = figuresOn('2026-03-10');

    expect($figures['credit_card_statement'])->toBeNull()
        ->and($figures['credit_card_usage'])->toMatchArray([
            'limit' => 1000,
            'used' => 2500,
            'available' => -1500,
            'period_from' => '2026-03-01',
            'period_to' => '2026-03-31',
        ])
        ->and($figures['credit_card_usage']['daily'])->toHaveCount(10);
});

it('leaves the available credit empty while no limit is set', function () {
    cardTransaction(-1000, '2026-03-07');

    expect(figuresOn('2026-03-10')['credit_card_usage'])->toMatchArray([
        'limit' => null,
        'used' => 1000,
        'available' => null,
    ]);
});

// -------------------------------------------------------------------
// Account page payload
// -------------------------------------------------------------------

it('sends neither the details nor the estimate while the flag is off', function () {
    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->missing('account.credit_card_detail')
            ->missing('account.credit_card_statement')
            ->missing('account.credit_card_usage')
            ->where('features.creditCardStatements', false));
});

it('sends the details and the estimate for a credit card when the flag is on', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', 'UTC'));
    cardTransaction(-1000, '2026-03-01');

    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->where('features.creditCardStatements', true)
            ->where('account.credit_card_detail.statement_closing_date', '2026-03-05')
            ->where('account.credit_card_detail.payment_due_date', '2026-03-20')
            ->where('account.credit_card_statement.next_payment.amount', 1000)
            ->where('account.credit_card_statement.next_payment.is_final', true));
});

it('sends the credit limit and the usage for a credit card when the flag is on', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', 'UTC'));
    test()->detail->update(['credit_limit' => 50000]);
    cardTransaction(-1000, '2026-03-01');

    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->where('account.credit_card_detail.credit_limit', 50000)
            ->where('account.credit_card_usage.limit', 50000)
            ->where('account.credit_card_usage.used', 1000)
            ->where('account.credit_card_usage.available', 49000)
            ->where('account.credit_card_usage.period_from', '2026-02-06')
            ->has('account.credit_card_usage.daily', 33));
});

it('treats a card with a limit but no statement dates as having no dates', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', 'UTC'));
    test()->detail->update(['statement_closing_date' => null, 'payment_due_date' => null, 'credit_limit' => 50000]);

    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->where('account.credit_card_detail.statement_closing_date', null)
            ->where('account.credit_card_detail.payment_due_date', null)
            ->where('account.credit_card_detail.credit_limit', 50000)
            ->where('account.credit_card_statement', null)
            ->where('account.credit_card_usage.period_from', '2026-03-01')
            ->where('account.credit_card_usage.available', 50000));
});

it('sends an empty state when the card has no statement dates yet', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    test()->detail->delete();

    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->where('account.credit_card_detail', null)
            ->where('account.credit_card_statement', null)
            ->where('account.credit_card_usage.limit', null));
});

it('leaves other account types alone when the flag is on', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    $checking = Account::factory()->create(['user_id' => test()->user->id, 'type' => AccountType::Checking]);

    actingAs(test()->user)
        ->get(route('accounts.show', $checking))
        ->assertInertia(fn ($page) => $page->missing('account.credit_card_statement'));
});

it('reads today in the user timezone', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    test()->user->update(['timezone' => 'America/Argentina/Buenos_Aires']);

    // Already the 21st in UTC, still the 20th (the due date) in Buenos Aires.
    $this->travelTo(CarbonImmutable::parse('2026-03-21 01:00', 'UTC'));

    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->where('account.credit_card_statement.next_payment.due_date', '2026-03-20')
            ->where('account.credit_card_statement.next_payment.is_final', true));
});

// -------------------------------------------------------------------
// Saving the statement dates
// -------------------------------------------------------------------

it('saves the statement dates', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    test()->detail->delete();

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-04-25',
            'payment_due_date' => '2026-05-10',
        ])
        ->assertRedirect(route('accounts.show', test()->card));

    assertDatabaseHas('credit_card_details', [
        'account_id' => test()->card->id,
        'statement_closing_date' => '2026-04-25',
        'payment_due_date' => '2026-05-10',
    ]);
});

it('replaces the anchor when the dates are edited', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-04-07',
            'payment_due_date' => '2026-04-22',
        ])
        ->assertRedirect();

    expect(CreditCardDetail::query()->where('account_id', test()->card->id)->count())->toBe(1)
        ->and(test()->detail->fresh()->statement_closing_date->toDateString())->toBe('2026-04-07');
});

it('clears the statement dates', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);

    actingAs(test()->user)
        ->delete(route('accounts.credit-card-detail.destroy', test()->card))
        ->assertRedirect(route('accounts.show', test()->card));

    assertDatabaseMissing('credit_card_details', ['account_id' => test()->card->id]);
});

it('keeps the credit limit when the statement dates are cleared', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    test()->detail->update(['credit_limit' => 50000]);

    actingAs(test()->user)
        ->delete(route('accounts.credit-card-detail.destroy', test()->card))
        ->assertRedirect();

    expect(test()->detail->fresh())
        ->statement_closing_date->toBeNull()
        ->payment_due_date->toBeNull()
        ->credit_limit->toBe(50000);
});

it('sets the credit limit without touching the statement dates', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), ['credit_limit' => 150000])
        ->assertSessionHasNoErrors();

    expect(test()->detail->fresh())
        ->statement_closing_date->toDateString()->toBe('2026-03-05')
        ->credit_limit->toBe(150000);
});

it('saves a credit limit without statement dates', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    test()->detail->delete();

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => null,
            'payment_due_date' => null,
            'credit_limit' => 150000,
        ])
        ->assertSessionHasNoErrors();

    assertDatabaseHas('credit_card_details', [
        'account_id' => test()->card->id,
        'statement_closing_date' => null,
        'credit_limit' => 150000,
    ]);
});

it('forgets the card details once neither dates nor limit are left', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    test()->detail->update(['credit_limit' => 50000]);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => null,
            'payment_due_date' => null,
            'credit_limit' => null,
        ])
        ->assertSessionHasNoErrors();

    assertDatabaseMissing('credit_card_details', ['account_id' => test()->card->id]);
});

it('rejects a negative credit limit', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), ['credit_limit' => -1])
        ->assertSessionHasErrors('credit_limit');
});

// -------------------------------------------------------------------
// Credit limit at creation
// -------------------------------------------------------------------

it('saves the credit limit a credit card is created with', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);

    actingAs(test()->user)
        ->post(route('accounts.store'), [
            'name' => 'Visa',
            'type' => 'credit_card',
            'currency_code' => 'EUR',
            'credit_limit' => 300000,
        ])
        ->assertSessionHasNoErrors();

    $detail = Account::query()->where('name', 'Visa')->sole()->creditCardDetail;

    expect($detail)
        ->credit_limit->toBe(300000)
        ->statement_closing_date->toBeNull();
});

it('creates no card details when a credit card is created without a limit', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);

    actingAs(test()->user)
        ->post(route('accounts.store'), [
            'name' => 'Visa',
            'type' => 'credit_card',
            'currency_code' => 'EUR',
            'credit_limit' => null,
        ])
        ->assertSessionHasNoErrors();

    expect(Account::query()->where('name', 'Visa')->sole()->creditCardDetail)->toBeNull();
});

it('ignores a credit limit while the flag is off', function () {
    actingAs(test()->user)
        ->post(route('accounts.store'), [
            'name' => 'Visa',
            'type' => 'credit_card',
            'currency_code' => 'EUR',
            'credit_limit' => 300000,
        ])
        ->assertSessionHasNoErrors();

    expect(Account::query()->where('name', 'Visa')->sole()->creditCardDetail)->toBeNull();
});

it('rejects statement dates that do not make sense', function (array $payload, string $field) {
    Feature::for(test()->user)->activate(CreditCardStatements::class);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'missing closing date' => [['payment_due_date' => '2026-05-10'], 'statement_closing_date'],
    'missing due date' => [['statement_closing_date' => '2026-04-25'], 'payment_due_date'],
    'not a date' => [['statement_closing_date' => 'soon', 'payment_due_date' => '2026-05-10'], 'statement_closing_date'],
    'due before closing' => [['statement_closing_date' => '2026-04-25', 'payment_due_date' => '2026-04-20'], 'payment_due_date'],
    'due on the closing day' => [['statement_closing_date' => '2026-04-25', 'payment_due_date' => '2026-04-25'], 'payment_due_date'],
    'due too long after closing' => [['statement_closing_date' => '2026-04-01', 'payment_due_date' => '2026-05-17'], 'payment_due_date'],
]);

it('accepts a due date exactly 45 days after closing', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-04-01',
            'payment_due_date' => '2026-05-16',
        ])
        ->assertSessionHasNoErrors();
});

it('hides the statement endpoints while the flag is off', function () {
    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-04-25',
            'payment_due_date' => '2026-05-10',
        ])
        ->assertNotFound();

    actingAs(test()->user)
        ->delete(route('accounts.credit-card-detail.destroy', test()->card))
        ->assertNotFound();

    assertDatabaseHas('credit_card_details', ['account_id' => test()->card->id, 'statement_closing_date' => '2026-03-05']);
});

it('does not let another user set the statement dates', function () {
    $intruder = User::factory()->onboarded()->create();
    Feature::for($intruder)->activate(CreditCardStatements::class);

    actingAs($intruder)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-04-25',
            'payment_due_date' => '2026-05-10',
        ])
        ->assertForbidden();

    actingAs($intruder)
        ->delete(route('accounts.credit-card-detail.destroy', test()->card))
        ->assertForbidden();
});

it('only takes statement dates on a credit card', function () {
    Feature::for(test()->user)->activate(CreditCardStatements::class);
    $checking = Account::factory()->create(['user_id' => test()->user->id, 'type' => AccountType::Checking]);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', $checking), [
            'statement_closing_date' => '2026-04-25',
            'payment_due_date' => '2026-05-10',
        ])
        ->assertNotFound();

    assertDatabaseMissing('credit_card_details', ['account_id' => $checking->id]);
});
