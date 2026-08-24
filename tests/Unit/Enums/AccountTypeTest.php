<?php

use App\Enums\AccountType;

it('supports invested amount for investment accounts', function () {
    expect(AccountType::Investment->supportsInvestedAmount())->toBeTrue();
});

it('supports invested amount for retirement accounts', function () {
    expect(AccountType::Retirement->supportsInvestedAmount())->toBeTrue();
});

it('supports invested amount for savings accounts', function () {
    expect(AccountType::Savings->supportsInvestedAmount())->toBeTrue();
});

it('does not support invested amount for non-investment account types', function (AccountType $type) {
    expect($type->supportsInvestedAmount())->toBeFalse();
})->with([
    'checking' => AccountType::Checking,
    'credit card' => AccountType::CreditCard,
    'loan' => AccountType::Loan,
    'real estate' => AccountType::RealEstate,
    'others' => AccountType::Others,
]);

it('reduces net worth for liability account types', function (AccountType $type) {
    expect($type->reducesNetWorth())->toBeTrue();
})->with([
    'loan' => AccountType::Loan,
]);

it('does not reduce net worth for asset account types', function (AccountType $type) {
    expect($type->reducesNetWorth())->toBeFalse();
})->with([
    'checking' => AccountType::Checking,
    'credit card' => AccountType::CreditCard,
    'savings' => AccountType::Savings,
    'investment' => AccountType::Investment,
    'retirement' => AccountType::Retirement,
    'real estate' => AccountType::RealEstate,
    'others' => AccountType::Others,
]);

it('excludes credit cards from the net worth total', function () {
    expect(AccountType::CreditCard->countsInNetWorth())->toBeFalse();
});

it('counts non credit card account types in the net worth total', function (AccountType $type) {
    expect($type->countsInNetWorth())->toBeTrue();
})->with([
    'checking' => AccountType::Checking,
    'savings' => AccountType::Savings,
    'investment' => AccountType::Investment,
    'retirement' => AccountType::Retirement,
    'real estate' => AccountType::RealEstate,
    'loan' => AccountType::Loan,
    'others' => AccountType::Others,
]);

it('is non-transactional for balance-only account types', function (AccountType $type) {
    expect($type->isNonTransactional())->toBeTrue();
})->with([
    'investment' => AccountType::Investment,
    'retirement' => AccountType::Retirement,
    'real estate' => AccountType::RealEstate,
]);

it('is transactional for standard account types', function (AccountType $type) {
    expect($type->isNonTransactional())->toBeFalse();
})->with([
    'checking' => AccountType::Checking,
    'savings' => AccountType::Savings,
    'credit card' => AccountType::CreditCard,
    'loan' => AccountType::Loan,
    'others' => AccountType::Others,
]);
