<?php

use App\Enums\AccountType;

it('supports invested amount for investment accounts', function () {
    expect(AccountType::Investment->supportsInvestedAmount())->toBeTrue();
});

it('supports invested amount for retirement accounts', function () {
    expect(AccountType::Retirement->supportsInvestedAmount())->toBeTrue();
});

it('does not support invested amount for non-investment account types', function (AccountType $type) {
    expect($type->supportsInvestedAmount())->toBeFalse();
})->with([
    'checking' => AccountType::Checking,
    'credit card' => AccountType::CreditCard,
    'loan' => AccountType::Loan,
    'savings' => AccountType::Savings,
    'others' => AccountType::Others,
]);
