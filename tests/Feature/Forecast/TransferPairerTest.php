<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Data\TransferLeg;
use App\Services\Forecast\TransferPairer;

/**
 * @return array<string, TransferLeg>
 */
function pairLegs(Transaction ...$transactions): array
{
    return app(TransferPairer::class)->pair(collect($transactions)->each->load('category'));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->checking = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
    $this->savings = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'type' => AccountType::Savings]);
});

it('pairs opposite amounts between two of the user\'s accounts within two days', function () {
    $out = forecastCharge($this->checking, '2026-10-05', -50000, 'TRASPASO');
    $in = forecastCharge($this->savings, '2026-10-07', 50000, 'TRASPASO RECIBIDO');

    $legs = pairLegs($out, $in);

    expect($legs[$out->id])->toEqual(new TransferLeg($out->id, $in->id, $this->savings->id))
        ->and($legs[$in->id])->toEqual(new TransferLeg($in->id, $out->id, $this->checking->id));
});

it('does not pair legs that are not a transfer between two accounts', function (string $inDate, int $inAmount, string $inAccount, string $inCurrency) {
    $out = forecastCharge($this->checking, '2026-10-05', -50000, 'PAGO');
    $in = forecastCharge($this->{$inAccount}, $inDate, $inAmount, 'INGRESO', attributes: ['currency_code' => $inCurrency]);

    expect(pairLegs($out, $in))->toBe([]);
})->with([
    'three days apart' => ['2026-10-08', 50000, 'savings', 'EUR'],
    'different amount' => ['2026-10-05', 49999, 'savings', 'EUR'],
    'same account' => ['2026-10-05', 50000, 'checking', 'EUR'],
    'different currency' => ['2026-10-05', 50000, 'savings', 'USD'],
]);

it('pairs each leg once, with the closest one in time', function () {
    $out = forecastCharge($this->checking, '2026-10-05', -50000, 'TRASPASO');
    $far = forecastCharge($this->savings, '2026-10-07', 50000, 'TRASPASO RECIBIDO');
    $near = forecastCharge($this->savings, '2026-10-05', 50000, 'TRASPASO RECIBIDO');

    $legs = pairLegs($out, $far, $near);

    expect($legs[$out->id]->counterpartTransactionId)->toBe($near->id)
        ->and($legs)->not->toHaveKey($far->id);
});

it('takes a transfer, saving or investment category as one leg with an unknown counterpart', function (CategoryType $type) {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => $type]);
    $leg = forecastCharge($this->checking, '2026-10-05', -20000, 'APORTACION', $category);
    $spend = forecastCharge($this->checking, '2026-10-05', -2000, 'CAFE', forecastExpenseCategory($this->user));

    $legs = pairLegs($leg, $spend);

    expect($legs)->toHaveKeys([$leg->id])->not->toHaveKey($spend->id)
        ->and($legs[$leg->id]->isInternal())->toBeFalse();
})->with([CategoryType::Transfer, CategoryType::Savings, CategoryType::Investment]);

it('never pairs a leg filed as an expense or an income', function () {
    $rent = forecastCharge($this->checking, '2026-10-01', -100000, 'ALQUILER', forecastExpenseCategory($this->user));
    $salary = forecastCharge($this->savings, '2026-10-01', 100000, 'NOMINA', Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income]));

    expect(pairLegs($rent, $salary))->toBe([]);
});

it('gives a credit card payment five days to settle', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $payment = forecastCharge($this->checking, '2026-10-05', -90000, 'PAGO TARJETA');
    $settled = forecastCharge($card, '2026-10-09', 90000, 'PAGO RECIBIDO');

    expect(pairLegs($payment, $settled)[$payment->id]->counterpartAccountId)->toBe($card->id);
});
