<?php

use App\Enums\AccountType;

it('supports an invested amount only on investment, retirement and savings accounts', function (AccountType $type, bool $supportsInvestedAmount) {
    expect($type->supportsInvestedAmount())->toBe($supportsInvestedAmount);
})->with([
    'investment' => [AccountType::Investment, true],
    'retirement' => [AccountType::Retirement, true],
    'savings' => [AccountType::Savings, true],
    'checking' => [AccountType::Checking, false],
    'credit card' => [AccountType::CreditCard, false],
    'loan' => [AccountType::Loan, false],
    'real estate' => [AccountType::RealEstate, false],
    'others' => [AccountType::Others, false],
]);

it('sets money aside only on savings, investment and retirement accounts', function (AccountType $type, bool $isSetAside) {
    expect($type->isSetAside())->toBe($isSetAside);
})->with([
    'savings' => [AccountType::Savings, true],
    'investment' => [AccountType::Investment, true],
    'retirement' => [AccountType::Retirement, true],
    'checking' => [AccountType::Checking, false],
    'credit card' => [AccountType::CreditCard, false],
    'loan' => [AccountType::Loan, false],
    'real estate' => [AccountType::RealEstate, false],
    'others' => [AccountType::Others, false],
]);

it('reduces net worth only for liability account types', function (AccountType $type, bool $reducesNetWorth) {
    expect($type->reducesNetWorth())->toBe($reducesNetWorth);
})->with([
    'loan' => [AccountType::Loan, true],
    'checking' => [AccountType::Checking, false],
    'credit card' => [AccountType::CreditCard, false],
    'savings' => [AccountType::Savings, false],
    'investment' => [AccountType::Investment, false],
    'retirement' => [AccountType::Retirement, false],
    'real estate' => [AccountType::RealEstate, false],
    'others' => [AccountType::Others, false],
]);

it('counts every account type but credit cards in the net worth total', function (AccountType $type, bool $countsInNetWorth) {
    expect($type->countsInNetWorth())->toBe($countsInNetWorth);
})->with([
    'credit card' => [AccountType::CreditCard, false],
    'checking' => [AccountType::Checking, true],
    'savings' => [AccountType::Savings, true],
    'investment' => [AccountType::Investment, true],
    'retirement' => [AccountType::Retirement, true],
    'real estate' => [AccountType::RealEstate, true],
    'loan' => [AccountType::Loan, true],
    'others' => [AccountType::Others, true],
]);

it('is non-transactional only for balance-only account types', function (AccountType $type, bool $isNonTransactional) {
    expect($type->isNonTransactional())->toBe($isNonTransactional);
})->with([
    'investment' => [AccountType::Investment, true],
    'retirement' => [AccountType::Retirement, true],
    'real estate' => [AccountType::RealEstate, true],
    'checking' => [AccountType::Checking, false],
    'savings' => [AccountType::Savings, false],
    'credit card' => [AccountType::CreditCard, false],
    'loan' => [AccountType::Loan, false],
    'others' => [AccountType::Others, false],
]);
