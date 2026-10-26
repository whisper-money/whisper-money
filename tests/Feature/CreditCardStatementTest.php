<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\StatementFrequency;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CreditCards\CreditCardStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

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

it('adds up a week of purchases on a weekly card', function () {
    // Closes every Thursday and is charged the Monday after.
    test()->detail->update([
        'statement_closing_date' => '2026-10-01',
        'payment_due_date' => '2026-10-05',
        'statement_frequency' => StatementFrequency::Weekly,
    ]);
    cardTransaction(-1000, '2026-09-24');
    cardTransaction(-2000, '2026-09-25');
    cardTransaction(-4000, '2026-10-01');
    cardTransaction(-8000, '2026-10-02');

    $figures = figuresOn('2026-10-03');

    expect($figures['credit_card_statement']['next_payment'])->toMatchArray([
        'period_from' => '2026-09-25',
        'closing_date' => '2026-10-01',
        'due_date' => '2026-10-05',
        'amount' => 6000,
        'is_final' => true,
    ])->and($figures['credit_card_statement']['current_cycle'])->toMatchArray([
        'period_from' => '2026-10-02',
        'closing_date' => '2026-10-08',
        'due_date' => '2026-10-12',
        'amount' => 8000,
    ])->and($figures['credit_card_usage'])->toMatchArray([
        'used' => 14000,
        'period_from' => '2026-09-25',
        'period_to' => '2026-10-08',
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
// Payment attribution
// -------------------------------------------------------------------

it('attributes a payment made between closing and due date to the statement that just closed', function () {
    cardTransaction(-3000, '2026-01-10');
    $previous = cardTransaction(3000, '2026-02-12', ['category_id' => test()->transfer->id]);
    $next = cardTransaction(1200, '2026-03-08', ['category_id' => null]);

    expect(estimateOn('2026-03-10')['payments'])->toBe([
        ['transaction_id' => $previous->id, 'date' => '2026-02-12', 'amount' => 3000, 'period_from' => '2026-01-06', 'closing_date' => '2026-02-05', 'due_date' => '2026-02-20'],
        ['transaction_id' => $next->id, 'date' => '2026-03-08', 'amount' => 1200, 'period_from' => '2026-02-06', 'closing_date' => '2026-03-05', 'due_date' => '2026-03-20'],
    ]);
});

it('attributes a payment made after the due date to the last statement closed before it', function () {
    cardTransaction(2500, '2026-02-25', ['category_id' => test()->transfer->id]);

    expect(estimateOn('2026-03-10')['payments'])->sequence(
        fn ($payment) => $payment->toMatchArray(['period_from' => '2026-01-06', 'closing_date' => '2026-02-05']),
    );
});

describe('a weekly statement charged the day it closes', function () {
    // Closes every Thursday and is charged that same Thursday: purchases from
    // Friday through Thursday.
    beforeEach(function () {
        test()->detail->update([
            'statement_frequency' => StatementFrequency::Weekly,
            'statement_closing_date' => '2026-10-15',
            'payment_due_date' => '2026-10-15',
        ]);

        cardTransaction(-4200, '2026-10-12');
        test()->payment = cardTransaction(4200, '2026-10-15', ['category_id' => test()->transfer->id]);
    });

    it('attributes a payment on the closing day to the statement closing that day', function () {
        expect(estimateOn('2026-10-15')['payments'])->toBe([
            ['transaction_id' => test()->payment->id, 'date' => '2026-10-15', 'amount' => 4200, 'period_from' => '2026-10-09', 'closing_date' => '2026-10-15', 'due_date' => '2026-10-15'],
        ]);
    });

    it('charges the statement closing that day as the next payment, already paid', function () {
        expect(estimateOn('2026-10-15')['next_payment'])->toBe([
            'period_from' => '2026-10-09',
            'closing_date' => '2026-10-15',
            'due_date' => '2026-10-15',
            'amount' => 4200,
            'is_final' => false,
            'paid' => 4200,
        ]);
    });

    it('moves on to the open cycle the day after, with the charged statement settled', function () {
        $estimate = estimateOn('2026-10-16');

        expect($estimate['next_payment'])->toMatchArray([
            'period_from' => '2026-10-16',
            'closing_date' => '2026-10-22',
            'due_date' => '2026-10-22',
            'amount' => 0,
            'is_final' => false,
            'paid' => 0,
        ])->and($estimate['payments'])->sequence(
            fn ($payment) => $payment->toMatchArray(['date' => '2026-10-15', 'closing_date' => '2026-10-15']),
        )->and($estimate['previous_statement'])->toMatchArray([
            'period_from' => '2026-10-09',
            'closing_date' => '2026-10-15',
            'purchases' => 4200,
            'paid' => 4200,
            'pending' => 0,
        ]);
    });
});

it('attributes a payment on the closing day of a monthly statement charged that day', function () {
    test()->detail->update(['statement_closing_date' => '2026-03-05', 'payment_due_date' => '2026-03-05']);
    cardTransaction(-3000, '2026-02-20');
    cardTransaction(3000, '2026-03-05', ['category_id' => test()->transfer->id]);

    expect(estimateOn('2026-03-10')['previous_statement'])->toMatchArray([
        'period_from' => '2026-02-06',
        'closing_date' => '2026-03-05',
        'purchases' => 3000,
        'paid' => 3000,
        'pending' => 0,
    ]);
});

it('reads a payment on the date the bank booked it', function () {
    cardTransaction(2500, '2026-03-08', ['category_id' => null, 'source_date' => '2026-03-04']);

    expect(estimateOn('2026-03-10')['payments'][0])->toMatchArray(['date' => '2026-03-04', 'closing_date' => '2026-02-05']);
});

it('says how much of the next payment is already paid', function () {
    cardTransaction(-5000, '2026-02-10');
    cardTransaction(2000, '2026-03-08', ['category_id' => test()->transfer->id]);

    expect(estimateOn('2026-03-10')['next_payment'])->toMatchArray(['amount' => 5000, 'paid' => 2000]);
});

it('lists neither payments booked after today nor transfers out of the card', function () {
    cardTransaction(2000, '2026-03-12', ['category_id' => test()->transfer->id]);
    cardTransaction(-300, '2026-03-08', ['category_id' => test()->transfer->id]);

    expect(estimateOn('2026-03-10')['payments'])->toBe([]);
});

it('sums up the previous statement against what was paid on it', function () {
    cardTransaction(-3000, '2026-01-10');
    cardTransaction(-2000, '2026-02-05');
    cardTransaction(4000, '2026-02-12', ['category_id' => test()->transfer->id]);
    cardTransaction(-700, '2026-02-07');

    expect(estimateOn('2026-03-10')['previous_statement'])->toBe([
        'period_from' => '2026-01-06',
        'closing_date' => '2026-02-05',
        'due_date' => '2026-02-20',
        'purchases' => 5000,
        'paid' => 4000,
        'pending' => 1000,
        'has_recorded_purchases' => true,
    ]);
});

it('floors the pending amount at zero when a statement is overpaid', function () {
    cardTransaction(-1000, '2026-01-10');
    cardTransaction(4000, '2026-02-12', ['category_id' => test()->transfer->id]);

    expect(estimateOn('2026-03-10')['previous_statement'])->toMatchArray(['purchases' => 1000, 'paid' => 4000, 'pending' => 0]);
});

it('flags a paid statement from before the card has any recorded purchase', function () {
    cardTransaction(4000, '2026-02-12', ['category_id' => test()->transfer->id]);
    cardTransaction(-500, '2026-02-10');

    expect(estimateOn('2026-03-10')['previous_statement'])->toMatchArray([
        'purchases' => 0,
        'paid' => 4000,
        'pending' => 0,
        'has_recorded_purchases' => false,
    ]);
});

it('knows an empty paid statement has recorded purchases from before it', function () {
    cardTransaction(-800, '2025-12-20');
    cardTransaction(4000, '2026-02-12', ['category_id' => test()->transfer->id]);

    expect(estimateOn('2026-03-10')['previous_statement'])->toMatchArray(['purchases' => 0, 'has_recorded_purchases' => true]);
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

it('subtracts a payment on the statement still to be charged from the day it is booked', function () {
    test()->detail->update(['credit_limit' => 10000]);
    cardTransaction(-5000, '2026-02-10');
    cardTransaction(-1000, '2026-03-07');
    cardTransaction(3000, '2026-03-08', ['category_id' => test()->transfer->id]);

    $usage = figuresOn('2026-03-10')['credit_card_usage'];

    expect($usage)->toMatchArray(['used' => 3000, 'available' => 7000])
        ->and($usage['daily'][29])->toBe(['date' => '2026-03-07', 'used' => 6000])
        ->and($usage['daily'][30])->toBe(['date' => '2026-03-08', 'used' => 3000]);
});

it('subtracts an uncategorized payment on the statement still to be charged', function () {
    cardTransaction(-5000, '2026-02-10');
    cardTransaction(5000, '2026-03-08', ['category_id' => null]);

    expect(figuresOn('2026-03-10')['credit_card_usage']['used'])->toBe(0);
});

it('does not subtract a payment on the statement before the one still to be charged', function () {
    cardTransaction(-3000, '2026-02-10');
    cardTransaction(3000, '2026-02-12', ['category_id' => test()->transfer->id]);

    expect(figuresOn('2026-03-10')['credit_card_usage']['used'])->toBe(3000);
});

it('does not subtract a payment made after the statement was charged from the open cycle', function () {
    cardTransaction(-2500, '2026-03-15');
    cardTransaction(4000, '2026-03-21', ['category_id' => test()->transfer->id]);

    expect(figuresOn('2026-03-25')['credit_card_usage']['used'])->toBe(2500);
});

it('does not subtract a payment booked after today', function () {
    cardTransaction(-5000, '2026-02-10');
    cardTransaction(5000, '2026-03-12', ['category_id' => test()->transfer->id]);

    expect(figuresOn('2026-03-10')['credit_card_usage']['used'])->toBe(5000);
});

it('floors the usage at zero when a payment overpays, counting the purchases after it', function () {
    test()->detail->update(['credit_limit' => 10000]);
    cardTransaction(-1000, '2026-02-10');
    cardTransaction(4000, '2026-03-08', ['category_id' => test()->transfer->id]);
    cardTransaction(-500, '2026-03-09');

    $usage = figuresOn('2026-03-10')['credit_card_usage'];

    expect($usage)->toMatchArray(['used' => 500, 'available' => 9500])
        ->and($usage['daily'][29])->toBe(['date' => '2026-03-07', 'used' => 1000])
        ->and($usage['daily'][30])->toBe(['date' => '2026-03-08', 'used' => 0])
        ->and($usage['daily'][31])->toBe(['date' => '2026-03-09', 'used' => 500]);
});

it('subtracts a payment on a weekly statement still to be charged', function () {
    test()->detail->update([
        'statement_frequency' => StatementFrequency::Weekly,
        'statement_closing_date' => '2026-03-01',
        'payment_due_date' => '2026-03-04',
    ]);
    cardTransaction(-2000, '2026-03-07');
    cardTransaction(-700, '2026-03-09');
    cardTransaction(2000, '2026-03-09', ['category_id' => test()->transfer->id]);

    expect(figuresOn('2026-03-10')['credit_card_usage'])->toMatchArray(['used' => 700, 'period_from' => '2026-03-02']);
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

it('sends the details and the estimate for a credit card', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', 'UTC'));
    cardTransaction(-1000, '2026-03-01');

    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->where('account.credit_card_detail.statement_closing_date', '2026-03-05')
            ->where('account.credit_card_detail.payment_due_date', '2026-03-20')
            ->where('account.credit_card_detail.statement_frequency', 'monthly')
            ->where('account.credit_card_statement.next_payment.amount', 1000)
            ->where('account.credit_card_statement.next_payment.is_final', true));
});

it('sends the credit limit and the usage for a credit card', function () {
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
    test()->detail->delete();

    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->where('account.credit_card_detail', null)
            ->where('account.credit_card_statement', null)
            ->where('account.credit_card_usage.limit', null));
});

it('leaves other account types alone', function () {
    $checking = Account::factory()->create(['user_id' => test()->user->id, 'type' => AccountType::Checking]);

    actingAs(test()->user)
        ->get(route('accounts.show', $checking))
        ->assertInertia(fn ($page) => $page->missing('account.credit_card_statement'));
});

it('reads today in the user timezone', function () {
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
    actingAs(test()->user)
        ->delete(route('accounts.credit-card-detail.destroy', test()->card))
        ->assertRedirect(route('accounts.show', test()->card));

    assertDatabaseMissing('credit_card_details', ['account_id' => test()->card->id]);
});

it('keeps the credit limit when the statement dates are cleared', function () {
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
    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), ['credit_limit' => 150000])
        ->assertSessionHasNoErrors();

    expect(test()->detail->fresh())
        ->statement_closing_date->toDateString()->toBe('2026-03-05')
        ->credit_limit->toBe(150000);
});

it('saves a credit limit without statement dates', function () {
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

it('rejects a credit limit that is not positive', function (int $creditLimit) {
    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), ['credit_limit' => $creditLimit])
        ->assertSessionHasErrors('credit_limit');
})->with([-1, 0]);

// -------------------------------------------------------------------
// Credit limit at creation
// -------------------------------------------------------------------

it('saves the credit limit a credit card is created with', function () {
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

it('rejects statement dates that do not make sense', function (array $payload, string $field) {
    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'missing closing date' => [['payment_due_date' => '2026-05-10'], 'statement_closing_date'],
    'missing due date' => [['statement_closing_date' => '2026-04-25'], 'payment_due_date'],
    'not a date' => [['statement_closing_date' => 'soon', 'payment_due_date' => '2026-05-10'], 'statement_closing_date'],
    'due before closing' => [['statement_closing_date' => '2026-04-25', 'payment_due_date' => '2026-04-20'], 'payment_due_date'],
    'due too long after closing' => [['statement_closing_date' => '2026-04-01', 'payment_due_date' => '2026-05-17'], 'payment_due_date'],
    'unknown frequency' => [['statement_frequency' => 'biweekly'], 'statement_frequency'],
]);

it('accepts a statement charged the same day it closes', function (string $frequency) {
    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-10-15',
            'payment_due_date' => '2026-10-15',
            'statement_frequency' => $frequency,
        ])
        ->assertSessionHasNoErrors();

    expect(test()->detail->fresh()->payment_due_date->toDateString())->toBe('2026-10-15');
})->with(['monthly', 'weekly']);

it('rejects a statement charged the day before it closes', function (string $frequency) {
    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-10-15',
            'payment_due_date' => '2026-10-14',
            'statement_frequency' => $frequency,
        ])
        ->assertSessionHasErrors(['payment_due_date' => "The payment due date can't be before the statement closing date."]);
})->with(['monthly', 'weekly']);

it('accepts a due date exactly 45 days after closing', function () {
    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-04-01',
            'payment_due_date' => '2026-05-16',
        ])
        ->assertSessionHasNoErrors();
});

it('caps the gap between closing and due date by the statement frequency', function (string $frequency, string $dueDate, bool $passes) {
    $response = actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-04-01',
            'payment_due_date' => $dueDate,
            'statement_frequency' => $frequency,
        ]);

    $passes ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('payment_due_date');
})->with([
    'weekly, 7 days' => ['weekly', '2026-04-08', true],
    'weekly, 8 days' => ['weekly', '2026-04-09', false],
    'monthly, 45 days' => ['monthly', '2026-05-16', true],
    'monthly, 46 days' => ['monthly', '2026-05-17', false],
]);

it('checks the gap against the stored frequency when only the dates are sent', function () {
    test()->detail->update(['statement_frequency' => StatementFrequency::Weekly]);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-04-01',
            'payment_due_date' => '2026-04-21',
        ])
        ->assertSessionHasErrors('payment_due_date');
});

it('checks the stored dates when only the frequency is sent', function () {
    // The stored statement is charged 15 days after it closes.
    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), ['statement_frequency' => 'weekly'])
        ->assertSessionHasErrors('statement_frequency');

    expect(test()->detail->fresh()->statement_frequency)->toBe(StatementFrequency::Monthly);
});

it('saves a weekly statement frequency and sends it back', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00', 'UTC'));

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-10-01',
            'payment_due_date' => '2026-10-05',
            'statement_frequency' => 'weekly',
        ])
        ->assertSessionHasNoErrors();

    expect(test()->detail->fresh()->statement_frequency)->toBe(StatementFrequency::Weekly);

    actingAs(test()->user)
        ->get(route('accounts.show', test()->card))
        ->assertInertia(fn ($page) => $page
            ->where('account.credit_card_detail.statement_frequency', 'weekly')
            ->where('account.credit_card_statement.current_cycle.closing_date', '2026-10-08'));
});

it('keeps the stored statement frequency when it is not sent', function () {
    test()->detail->update(['statement_frequency' => StatementFrequency::Weekly]);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), [
            'statement_closing_date' => '2026-10-01',
            'payment_due_date' => '2026-10-05',
            'statement_frequency' => null,
        ])
        ->assertSessionHasNoErrors();

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', test()->card), ['credit_limit' => 50000])
        ->assertSessionHasNoErrors();

    expect(test()->detail->fresh()->statement_frequency)->toBe(StatementFrequency::Weekly);
});

it('forgets a card left with only its statement frequency', function () {
    test()->detail->update(['statement_frequency' => StatementFrequency::Weekly]);

    actingAs(test()->user)
        ->delete(route('accounts.credit-card-detail.destroy', test()->card))
        ->assertSessionHasNoErrors();

    assertDatabaseMissing('credit_card_details', ['account_id' => test()->card->id]);
});

it('does not let another user set the statement dates', function () {
    $intruder = User::factory()->onboarded()->create();

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
    $checking = Account::factory()->create(['user_id' => test()->user->id, 'type' => AccountType::Checking]);

    actingAs(test()->user)
        ->patch(route('accounts.credit-card-detail.update', $checking), [
            'statement_closing_date' => '2026-04-25',
            'payment_due_date' => '2026-05-10',
        ])
        ->assertNotFound();

    assertDatabaseMissing('credit_card_details', ['account_id' => $checking->id]);
});

it('refuses another user before validating against the stored card', function () {
    $intruder = User::factory()->onboarded()->create();

    actingAs($intruder)
        ->patch(route('accounts.credit-card-detail.update', test()->card), ['statement_frequency' => 'weekly'])
        ->assertForbidden();
});
