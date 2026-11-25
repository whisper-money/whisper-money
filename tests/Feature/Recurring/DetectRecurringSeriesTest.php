<?php

use App\Enums\CategoryType;
use App\Enums\RecurringCadence;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringSeries;
use App\Models\RecurringSeriesTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\DetectRecurringSeries;
use App\Services\Recurring\MerchantKeyBuilder;
use App\Services\TransactionSplitter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->detector = app(DetectRecurringSeries::class);
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);
    $this->category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
});

/**
 * Create one occurrence per month, ending $monthsAgoEnd months before today.
 *
 * @param  list<int>|null  $amounts  one amount per occurrence, defaulting to a flat fee
 * @return list<Transaction>
 */
function monthlyCharges(
    User $user,
    Account $account,
    string $creditor,
    int $count = 4,
    int $amount = -1299,
    int $monthsAgoEnd = 0,
    ?array $amounts = null,
    ?string $categoryId = null,
    string $currency = 'EUR',
): array {
    $anchor = CarbonImmutable::today()->subMonths($monthsAgoEnd)->subDays(3);

    return collect(range(0, $count - 1))
        ->map(fn (int $index): Transaction => Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $categoryId,
            'creditor_name' => $creditor,
            'transaction_date' => $anchor->subMonths($count - 1 - $index)->toDateString(),
            'amount' => $amounts[$index] ?? $amount,
            'currency_code' => $currency,
        ]))
        ->all();
}

it('selects the merchant field according to transaction direction', function () {
    $builder = app(MerchantKeyBuilder::class);

    $cases = [
        [
            'amount' => 250000,
            'creditor_name' => 'ACCOUNT HOLDER',
            'debtor_name' => 'EXAMPLE EMPLOYER LTD',
            'expected' => ['debtor_name', 'employer example'],
        ],
        [
            'amount' => -250000,
            'creditor_name' => 'EXAMPLE EMPLOYER LTD',
            'debtor_name' => 'ACCOUNT HOLDER',
            'expected' => ['creditor_name', 'employer example'],
        ],
        [
            'amount' => 250000,
            'creditor_name' => null,
            'debtor_name' => 'EXAMPLE EMPLOYER LTD',
            'expected' => ['debtor_name', 'employer example'],
        ],
        [
            'amount' => -250000,
            'creditor_name' => 'EXAMPLE EMPLOYER LTD',
            'debtor_name' => null,
            'expected' => ['creditor_name', 'employer example'],
        ],
    ];

    foreach ($cases as $case) {
        $transaction = Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'amount' => $case['amount'],
            'creditor_name' => $case['creditor_name'],
            'debtor_name' => $case['debtor_name'],
        ]);

        expect($builder->keyFor($transaction, [], 0))->toBe($case['expected']);
    }
});

it('detects a monthly subscription', function () {
    monthlyCharges($this->user, $this->account, 'Netflix', categoryId: $this->category->id);

    expect($this->detector->forUser($this->user))->toBe(1);

    $series = RecurringSeries::query()->sole();

    expect($series->display_name)->toBe('Netflix')
        ->and($series->cadence)->toBe(RecurringCadence::Monthly)
        ->and($series->expected_amount)->toBe(-1299)
        ->and($series->amount_is_variable)->toBeFalse()
        ->and($series->direction)->toBe('expense')
        ->and($series->occurrence_count)->toBe(4)
        ->and($series->status)->toBe(RecurringSeriesStatus::Active)
        ->and($series->user_state)->toBe(RecurringSeriesUserState::Detected)
        ->and($series->category_id)->toBe($this->category->id)
        ->and($series->account_id)->toBe($this->account->id)
        ->and($series->transactions)->toHaveCount(4);
});

it('projects the next charge on the same day of the month', function () {
    monthlyCharges($this->user, $this->account, 'Spotify');

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();

    // Counting days walks a monthly charge backwards through the calendar:
    // thirty days after the 5th is the 4th, then the 3rd. What repeats is the
    // billing day, and it is recorded so a short month cannot erode it.
    expect($series->anchor_day)->toBe($series->last_occurred_on->day)
        ->and($series->next_expected_on->day)->toBe($series->last_occurred_on->day)
        ->and($series->next_expected_on->toDateString())
        ->toBe($series->last_occurred_on->addMonthNoOverflow()->toDateString());
});

it('records what a charge costs now beside what it has cost', function () {
    // A policy that went from 24,78 € to 103,82 €. The lifetime median still
    // reads as the old price for as long as the cheap years outnumber the dear
    // ones, and the screen was showing that as the amount due.
    monthlyCharges($this->user, $this->account, 'Generali', count: 15, amounts: [
        ...array_fill(0, 12, -2478),
        ...array_fill(0, 3, -10382),
    ]);

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();

    expect($series->expected_amount)->toBe(-2478)
        ->and($series->recent_amount)->toBe(-10382)
        ->and($series->chargeAmount())->toBe(-10382)
        // A price that moved and then held is not a bill that varies. Read over
        // the whole history the step itself looks like variation.
        ->and($series->amount_is_variable)->toBeFalse();
});

it('gives a quarterly series a current amount too', function () {
    // Price-change detection needs two full windows, so at six charges a
    // quarterly premium would wait years and an annual one for ever. The
    // current amount is taken from whatever history exists.
    monthlyCharges($this->user, $this->account, 'Insurer', count: 4, amounts: [
        -9000, -9000, -12000, -12000,
    ]);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->recent_amount)->toBe(-12000);
});

it('falls back to the lifetime median where no scan has run yet', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1299,
        'recent_amount' => null,
    ]);

    expect($series->chargeAmount())->toBe(-1299);
});

it('detects a yearly series', function () {
    // Regression: the lookback window has to span min_occurrences of the
    // slowest cadence. At thirteen months a yearly series needed more history
    // than detection was allowed to read, so Yearly could never be reached.
    $anchor = CarbonImmutable::today()->subDays(10);

    foreach ([2, 1, 0] as $yearsAgo) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'creditor_name' => 'Dominio Anual',
            'transaction_date' => $anchor->subYears($yearsAgo)->toDateString(),
            'amount' => -1200,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(1);

    $series = RecurringSeries::query()->sole();

    expect($series->cadence)->toBe(RecurringCadence::Yearly)
        ->and($series->occurrence_count)->toBe(3)
        ->and($series->status)->toBe(RecurringSeriesStatus::Active);
});

it('flags a series whose amount moves as variable', function () {
    monthlyCharges($this->user, $this->account, 'Endesa', amounts: [-4000, -9500, -6200, -12000]);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->amount_is_variable)->toBeTrue();
});

it('keeps a steady series marked as fixed', function () {
    monthlyCharges($this->user, $this->account, 'Netflix', amounts: [-1299, -1299, -1299, -1350]);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->amount_is_variable)->toBeFalse();
});

it('gives a confirmed series longer before calling it cancelled', function () {
    // Four months since the last charge on a monthly series: past the ordinary
    // grace period, inside the one a confirmed commitment gets.
    monthlyCharges($this->user, $this->account, 'Seguro', monthsAgoEnd: 2);
    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Lapsed);

    RecurringSeries::query()->sole()->update(['user_state' => RecurringSeriesUserState::Confirmed]);
    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Active);
});

it('still lapses a confirmed series once it is long gone', function () {
    monthlyCharges($this->user, $this->account, 'Seguro', monthsAgoEnd: 8);
    $this->detector->forUser($this->user);
    RecurringSeries::query()->sole()->update(['user_state' => RecurringSeriesUserState::Confirmed]);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Lapsed);
});

it('marks a series that stopped billing as lapsed', function () {
    monthlyCharges($this->user, $this->account, 'Gym', monthsAgoEnd: 4);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Lapsed);
});

it('ignores a merchant seen fewer than three times', function () {
    monthlyCharges($this->user, $this->account, 'Rare Shop', count: 2);

    expect($this->detector->forUser($this->user))->toBe(0);
    expect(RecurringSeries::query()->count())->toBe(0);
});

it('ignores transactions whose description is client-encrypted', function () {
    // No creditor name, so grouping would fall back to the description — which
    // the server cannot read for these rows.
    $anchor = CarbonImmutable::today()->subDays(3);

    foreach (range(0, 3) as $index) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'creditor_name' => null,
            'description' => 'ciphertext',
            'description_iv' => 'abcdefghijklmnop',
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -1299,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(0);
});

it('attributes a split charge to its largest line', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $home = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    $charges = monthlyCharges($this->user, $this->account, 'Mercadona', amount: -10000);

    foreach ($charges as $charge) {
        $charge->splits()->createMany([
            ['category_id' => $food->id, 'amount' => -7000, 'position' => 0],
            ['category_id' => $home->id, 'amount' => -3000, 'position' => 1],
        ]);
        $charge->forceFill(['category_id' => null])->save();
    }

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();

    expect($series->category_id)->toBe($food->id)
        ->and($series->expected_amount)->toBe(-10000);
});

it('detects a monthly charge whose latest occurrence was split, at its full amount, under its largest part', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $home = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $charges = monthlyCharges($this->user, $this->account, 'Mercadona', amount: -10000);
    $latest = end($charges);
    $parts = app(TransactionSplitter::class)->split($latest, [
        ['amount' => -3000, 'category_id' => $home->id],
        ['amount' => -7000, 'category_id' => $food->id],
    ]);

    expect($this->detector->forUser($this->user))->toBe(1);

    $series = RecurringSeries::query()->sole();

    expect($series->cadence)->toBe(RecurringCadence::Monthly)
        ->and($series->occurrence_count)->toBe(4)
        ->and($series->expected_amount)->toBe(-10000)
        ->and($series->recent_amount)->toBe(-10000)
        ->and($series->last_occurred_on->toDateString())->toBe($latest->transaction_date->toDateString())
        ->and($series->category_id)->toBe($food->id)
        // The pivot holds the original, and the series still reads it.
        ->and($series->transactions->pluck('id')->sort()->values()->all())->toBe(collect($charges)->pluck('id')->sort()->values()->all())
        ->and(RecurringSeriesTransaction::query()->whereIn('transaction_id', $parts->pluck('id'))->exists())->toBeFalse();

    // A second run finds the same charges and leaves the series as it is.
    expect($this->detector->previewForUser($this->user))->toMatchArray(['unchanged' => 1, 'created' => 0, 'updated' => 0]);
});

it('counts a split charge once among the descriptions that tell merchants apart', function () {
    $charges = monthlyCharges($this->user, $this->account, 'Netflix');
    $before = app(MerchantKeyBuilder::class)->documentFrequency(Transaction::query()->pluck('description'));

    app(TransactionSplitter::class)->split(end($charges), [['amount' => -1000], ['amount' => -299]]);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->occurrence_count)->toBe(4)
        ->and(app(MerchantKeyBuilder::class)->documentFrequency(Transaction::query()->movements()->pluck('description')))->toBe($before);
});

it('keeps income and expense from the same counterparty apart', function () {
    monthlyCharges($this->user, $this->account, 'Acme', amount: -5000);
    monthlyCharges($this->user, $this->account, 'Acme', amount: 250000);

    expect($this->detector->forUser($this->user))->toBe(2);

    expect(RecurringSeries::query()->where('direction', 'income')->sole()->expected_amount)->toBe(250000)
        ->and(RecurringSeries::query()->where('direction', 'expense')->sole()->expected_amount)->toBe(-5000);
});

it('groups income by debtor when both bank counterparty fields are populated', function () {
    $anchor = CarbonImmutable::today()->subDays(3);

    foreach (range(0, 3) as $index) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'creditor_name' => 'ACCOUNT HOLDER',
            'debtor_name' => 'EXAMPLE EMPLOYER LTD',
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => 250000,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(1);

    $series = RecurringSeries::query()->sole();

    expect($series->match_field)->toBe('debtor_name')
        ->and($series->merchant_key)->toBe('employer example')
        ->and($series->display_name)->toBe('EXAMPLE EMPLOYER LTD')
        ->and($series->direction)->toBe('income')
        ->and($series->expected_amount)->toBe(250000)
        ->and($series->currency_code)->toBe('EUR')
        ->and($series->occurrence_count)->toBe(4);
});

it('does not merge incoming counterparties that share the same creditor name', function () {
    $anchor = CarbonImmutable::today()->subDays(3);

    foreach (['EXAMPLE EMPLOYER LTD', 'Other Payer'] as $debtor) {
        foreach (range(0, 3) as $index) {
            Transaction::factory()->plaintext()->create([
                'user_id' => $this->user->id,
                'account_id' => $this->account->id,
                'creditor_name' => 'ACCOUNT HOLDER',
                'debtor_name' => $debtor,
                'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
                'amount' => $debtor === 'EXAMPLE EMPLOYER LTD' ? 250000 : 120000,
                'currency_code' => 'EUR',
            ]);
        }
    }

    expect($this->detector->forUser($this->user))->toBe(2);
    expect(RecurringSeries::query()->pluck('merchant_key')->sort()->values()->all())
        ->toBe(['employer example', 'other payer']);
});

it('keeps the same merchant billed in two currencies apart', function () {
    monthlyCharges($this->user, $this->account, 'Adobe', amount: -1999, currency: 'EUR');
    monthlyCharges($this->user, $this->account, 'Adobe', amount: -2499, currency: 'USD');

    expect($this->detector->forUser($this->user))->toBe(2);
    expect(RecurringSeries::query()->pluck('currency_code')->sort()->values()->all())
        ->toBe(['EUR', 'USD']);
});

it('does not duplicate series when detection runs again', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');

    $this->detector->forUser($this->user);
    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->count())->toBe(1)
        ->and(RecurringSeries::query()->sole()->transactions)->toHaveCount(4);
});

it('preserves a dismissed state and a renamed series across runs', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUser($this->user);

    RecurringSeries::query()->sole()->update([
        'user_state' => RecurringSeriesUserState::Ignored,
        'display_name' => 'Streaming',
    ]);

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();

    expect($series->user_state)->toBe(RecurringSeriesUserState::Ignored)
        ->and($series->display_name)->toBe('Streaming');
});

it('lapses a series the ledger no longer substantiates', function () {
    $charges = monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUser($this->user);

    Transaction::query()->whereIn('id', collect($charges)->pluck('id'))->forceDelete();
    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Lapsed);
});

it('revisits a space whose ledger was emptied', function () {
    // Spaces used to be derived from transactions alone, so emptying a space
    // meant its series were never looked at again and stayed active forever.
    $charges = monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUserEverywhere($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Active);

    Transaction::query()->whereIn('id', collect($charges)->pluck('id'))->forceDelete();
    $this->detector->forUserEverywhere($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Lapsed);
});

it('does not touch another user when reconciling an emptied space', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id, 'currency_code' => 'EUR']);
    monthlyCharges($other, $otherAccount, 'Disney');
    $this->detector->forUserEverywhere($other);

    $charges = monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUserEverywhere($this->user);
    Transaction::query()->whereIn('id', collect($charges)->pluck('id'))->forceDelete();
    $this->detector->forUserEverywhere($this->user);

    expect(RecurringSeries::query()->where('user_id', $other->id)->sole()->status)
        ->toBe(RecurringSeriesStatus::Active);
});

it('does not resurrect a series the user deleted', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUser($this->user);

    RecurringSeries::query()->sole()->delete();

    expect($this->detector->forUser($this->user))->toBe(0);
    expect(RecurringSeries::query()->count())->toBe(0)
        ->and(RecurringSeries::withTrashed()->count())->toBe(1);
});

it('does not leak series between users', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id, 'currency_code' => 'EUR']);

    monthlyCharges($this->user, $this->account, 'Netflix');
    monthlyCharges($other, $otherAccount, 'Disney');

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(RecurringSeries::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('ignores transactions older than the lookback window', function () {
    config()->set('recurring.lookback_months', 3);

    monthlyCharges($this->user, $this->account, 'Netflix', count: 4, monthsAgoEnd: 10);

    expect($this->detector->forUser($this->user))->toBe(0);
});

it('groups PayPal Spotify references into one stable obligation', function () {
    $anchor = CarbonImmutable::today()->subDays(3);

    foreach (range(0, 3) as $index) {
        $reference = strtoupper(str_pad(dechex($index + 100), 8, '0', STR_PAD_LEFT));

        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'creditor_name' => "PAYPAL *SPOTIFY {$reference}",
            'description' => "PAYPAL *SPOTIFY {$reference}",
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -2099,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(1);

    expect(RecurringSeries::query()->sole()->merchant_key)->toBe('spotify')
        ->and(RecurringSeries::query()->sole()->occurrence_count)->toBe(4);
});

it('keeps a description-only series when the creditor appears later', function () {
    // Far enough back that the creditor-bearing charge a month later is still
    // in the past: a future posting is not an observed occurrence.
    $anchor = CarbonImmutable::today()->subMonth()->subDays(3);

    foreach (range(0, 3) as $index) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'creditor_name' => null,
            'description' => 'SEPA DD DIGI '.strtoupper(str_pad(dechex($index + 200), 8, '0', STR_PAD_LEFT)),
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -1899,
            'currency_code' => 'EUR',
        ]);
    }

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();
    $series->update([
        'display_name' => 'Internet',
        'user_state' => RecurringSeriesUserState::Ignored,
    ]);
    $seriesId = $series->id;

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => 'DIGI',
        'description' => 'SEPA DD DIGI 9A8B7C6D',
        'transaction_date' => $anchor->addMonth()->toDateString(),
        'amount' => -1899,
        'currency_code' => 'EUR',
    ]);

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();

    expect($series->id)->toBe($seriesId)
        ->and($series->display_name)->toBe('Internet')
        ->and($series->user_state)->toBe(RecurringSeriesUserState::Ignored)
        ->and($series->occurrence_count)->toBe(5);
});

it('follows an obligation onto the account still paying it', function () {
    // A loan whose descriptor appears on two accounts: the one paying it now,
    // and the one it moved off a year ago. Both answer to the stored series —
    // one as a plain update, one as an account change — and applied in turn the
    // account that stopped could win, writing a year-old date onto a live
    // obligation. It then read as cancelled while its charges produced nothing.
    $stopped = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);

    monthlyCharges($this->user, $this->account, 'Car loan', count: 11, amount: -20652);
    monthlyCharges($this->user, $stopped, 'Car loan', count: 3, amount: -20652, monthsAgoEnd: 12);

    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->user->activeSpace()->id,
        'account_id' => $stopped->id,
        'merchant_key' => 'car loan',
        'display_name' => 'Coche',
        'currency_code' => 'EUR',
        'direction' => 'expense',
        'user_state' => RecurringSeriesUserState::Confirmed,
        'status' => RecurringSeriesStatus::Lapsed,
        'last_occurred_on' => CarbonImmutable::today()->subMonths(12),
        'first_occurred_on' => CarbonImmutable::today()->subMonths(14),
    ]);

    $this->detector->forUser($this->user);

    expect($series->fresh())
        ->status->toBe(RecurringSeriesStatus::Active)
        ->account_id->toBe($this->account->id)
        ->occurrence_count->toBe(11)
        // The name and the decision are the user's, and survive the move.
        ->display_name->toBe('Coche')
        ->user_state->toBe(RecurringSeriesUserState::Confirmed);

    // The account that stopped keeps a series of its own, saying so.
    $stale = RecurringSeries::query()->whereKeyNot($series->id)->sole();

    expect($stale->status)->toBe(RecurringSeriesStatus::Lapsed)
        ->and($stale->account_id)->toBe($stopped->id);
});

it('keeps same-provider contracts on separate accounts', function () {
    $otherAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);

    monthlyCharges($this->user, $this->account, 'Acme', amount: -1200);
    monthlyCharges($this->user, $otherAccount, 'Acme', amount: -8900);

    expect($this->detector->forUser($this->user))->toBe(2);
    expect(RecurringSeries::query()->pluck('account_id')->sort()->values()->all())
        ->toBe([$this->account->id, $otherAccount->id]);
});

it('does not turn own-holder transfers and social contributions into a subscription', function () {
    $anchor = CarbonImmutable::today()->subDays(3);
    $descriptions = [
        'TRANSFER TO SAVINGS',
        'SOCIAL SECURITY CONTRIBUTION',
        'TRANSFER TO SAVINGS',
        'SOCIAL SECURITY CONTRIBUTION',
    ];

    foreach ($descriptions as $index => $description) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'creditor_name' => $this->user->name,
            'description' => $description,
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -5000,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(0)
        ->and(RecurringSeries::query()->count())->toBe(0);
});

it('recognises the holder under a spelling only the bank uses', function () {
    // The profile name is rarely what a statement prints. Someone signed up as
    // "Alex" is ALEXANDRE MARTINEZ MORON to their bank, and until the app is
    // told so, every charge labelled that way groups under one key: the
    // social-security debit, the transfer to savings and the rest together.
    $this->user->update([
        'name' => 'Alex',
        'bank_aliases' => ['Alexandre Martínez Morón'],
    ]);

    $anchor = CarbonImmutable::today()->subDays(3);

    foreach (range(0, 3) as $index) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            // Unaccented, as a second bank writes it.
            'creditor_name' => 'ALEXANDRE MARTINEZ MORON',
            'description' => 'SOCIAL SECURITY CONTRIBUTION',
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -20586,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(1);

    expect(RecurringSeries::query()->sole())
        ->match_field->toBe('description')
        ->display_name->toBe('SOCIAL SECURITY CONTRIBUTION');
});

it('detects an obligation the bank labelled with the holder name', function () {
    $anchor = CarbonImmutable::today()->subDays(3);

    // A social-security direct debit arrives with the payer in the creditor
    // field, month after month. Treating that name as the counterparty used to
    // drop the charge entirely, so a real monthly obligation was simply absent
    // from the screen.
    foreach (range(0, 3) as $index) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'creditor_name' => $this->user->name,
            'description' => 'SOCIAL SECURITY CONTRIBUTION',
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -20586,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(1);

    $series = RecurringSeries::query()->sole();

    expect($series->match_field)->toBe('description')
        ->and($series->expected_amount)->toBe(-20586)
        ->and($series->display_name)->toBe('SOCIAL SECURITY CONTRIBUTION');
});

it('gives one identity to charges whose processor reference changes every month', function () {
    $anchor = CarbonImmutable::today()->subDays(3);
    $references = ['4F1A9B27', '35314369001', '4029357733', '3E7C2D91'];

    // PayPal rewrites the reference on every charge, in more than one format.
    // Keeping it in the identity left each charge alone under its own key, so
    // none of them ever reached the three occurrences a series needs.
    foreach ($references as $index => $reference) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'creditor_name' => "PAYPAL *SPOTIFY {$reference}",
            'description' => "PAYPAL *SPOTIFY {$reference}",
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -2099,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(1);

    expect(RecurringSeries::query()->sole())
        ->identity_key->toBe('spotify')
        ->occurrence_count->toBe(4);
});

it('collapses rows that a varying reference had split into one obligation', function () {
    // The state a ledger is left in by the old key: one obligation recorded as
    // several rows, because the bank wrote the provider's name differently each
    // time. Now that they answer to one identity, sending them to review would
    // freeze them — every later run reaches the same verdict and writes
    // nothing — so they collapse into the oldest, which holds the history.
    $confirmed = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->user->activeSpace()->id,
        'account_id' => $this->account->id,
        'match_field' => 'description',
        'merchant_key' => 'digi',
        'display_name' => 'Internet',
        'user_state' => RecurringSeriesUserState::Confirmed,
        'currency_code' => 'EUR',
        'direction' => 'expense',
        'first_occurred_on' => CarbonImmutable::today()->subMonths(10),
    ]);
    $duplicate = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->user->activeSpace()->id,
        'account_id' => $this->account->id,
        'match_field' => 'creditor_name',
        'merchant_key' => 'digi spain telecom',
        'display_name' => 'DIGI SPAIN TELECOM',
        'user_state' => RecurringSeriesUserState::Detected,
        'currency_code' => 'EUR',
        'direction' => 'expense',
        'first_occurred_on' => CarbonImmutable::today()->subMonths(4),
    ]);

    // A charge only the duplicate ever claimed, outside the window the current
    // run observes. Its link has to survive the collapse.
    $historical = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => 'DIGI',
        'transaction_date' => CarbonImmutable::today()->subMonths(30)->toDateString(),
        'amount' => -1899,
        'currency_code' => 'EUR',
    ]);
    $duplicate->transactions()->attach($historical->id);

    monthlyCharges($this->user, $this->account, 'DIGI', count: 4, amount: -1899);

    $this->detector->forUser($this->user);

    $survivor = RecurringSeries::query()->sole();

    expect($survivor->id)->toBe($confirmed->id)
        ->and($survivor->display_name)->toBe('Internet')
        ->and($survivor->user_state)->toBe(RecurringSeriesUserState::Confirmed)
        ->and($survivor->transactions()->whereKey($historical->id)->exists())->toBeTrue();

    $absorbed = RecurringSeries::withTrashed()->findOrFail($duplicate->id);

    expect($absorbed->trashed())->toBeTrue()
        ->and($absorbed->merged_into_id)->toBe($confirmed->id);
});

it('does not bring back a series the user deleted, even after absorbing another', function () {
    // Absorption soft deletes, and so does the user. The two must not read the
    // same: one is bookkeeping, the other is a decision to leave alone.
    $deleted = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->user->activeSpace()->id,
        'account_id' => $this->account->id,
        'merchant_key' => 'digi',
        'currency_code' => 'EUR',
        'direction' => 'expense',
    ]);
    $deleted->delete();

    monthlyCharges($this->user, $this->account, 'DIGI', count: 4, amount: -1899);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->count())->toBe(0);
});

it('does not count a future transaction as an observed occurrence', function () {
    monthlyCharges($this->user, $this->account, 'Spotify', count: 3);
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => 'Spotify',
        'transaction_date' => CarbonImmutable::today()->addDay()->toDateString(),
        'amount' => -1299,
        'currency_code' => 'EUR',
    ]);

    expect($this->detector->forUser($this->user))->toBe(1)
        ->and(RecurringSeries::query()->sole()->occurrence_count)->toBe(3)
        ->and(RecurringSeries::query()->sole()->last_occurred_on->isToday())->toBeFalse();
});

it('bounds the detection window with plain date comparisons the index can range over', function () {
    monthlyCharges($this->user, $this->account, 'Spotify', count: 3);

    DB::enableQueryLog();
    $this->detector->forUser($this->user);
    $windowed = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'from `transactions`') && str_contains($sql, 'transaction_date'));
    DB::disableQueryLog();

    // date(`transaction_date`) would keep the window off idx_transactions_budget_lookup
    // (user_id, deleted_at, transaction_date, id, category_id).
    expect($windowed)->not->toBeEmpty()
        ->each(fn ($sql) => $sql
            ->toContain('`transaction_date` >= ?')
            ->toContain('`transaction_date` <= ?')
            ->not->toContain('date(`transaction_date`)'));
});

it('keeps pivots for historical charges when an account is archived', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUser($this->user);

    $series = RecurringSeries::query()->sole();
    $pivotCount = $series->transactions()->count();
    $this->account->update(['archived_at' => now()]);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->transactions()->count())->toBe($pivotCount)
        ->and(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Lapsed);
});

it('previews reconciliation without writing series or pivots', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');

    $preview = $this->detector->previewForUser($this->user);

    expect($preview['created'])->toBe(1)
        ->and($preview['updated'])->toBe(0)
        ->and($preview['review_required'])->toBe(0)
        ->and(RecurringSeries::withTrashed()->count())->toBe(0)
        ->and(RecurringSeriesTransaction::query()->count())->toBe(0);
});

it('reports no changes on a second preview after applying once', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');

    $this->detector->previewForUser($this->user);
    $this->detector->forUser($this->user);
    $preview = $this->detector->previewForUser($this->user);

    expect($preview['created'])->toBe(0)
        ->and($preview['updated'])->toBe(0)
        ->and($preview['merged'])->toBe(0)
        ->and($preview['unchanged'])->toBe(1)
        ->and(RecurringSeries::query()->count())->toBe(1);
});

it('assigns reused transactions to a new identity without resurrecting a deleted series', function () {
    $charges = monthlyCharges($this->user, $this->account, 'Old Provider');
    $this->detector->forUser($this->user);
    $deleted = RecurringSeries::query()->sole();
    $deleted->delete();

    Transaction::query()
        ->whereIn('id', collect($charges)->pluck('id'))
        ->update(['creditor_name' => 'New Provider']);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->count())->toBe(1)
        ->and(RecurringSeries::withTrashed()->count())->toBe(2)
        ->and(RecurringSeries::withTrashed()->findOrFail($deleted->id)->trashed())->toBeTrue()
        ->and(RecurringSeries::query()->sole()->merchant_key)->toBe('new provider')
        ->and(RecurringSeries::query()->sole()->transactions)->toHaveCount(4);
});
