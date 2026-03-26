<?php

use App\Services\LoanAmortizationService;

beforeEach(function () {
    $this->service = new LoanAmortizationService;
});

it('calculates monthly payment for a standard loan', function () {
    // $200,000 loan at 3.5% for 30 years (360 months)
    $payment = $this->service->calculateMonthlyPayment(20000000, 3.5, 360);

    // Expected ~$898.09/month = ~89809 cents
    expect($payment)->toBeGreaterThan(89500)
        ->toBeLessThan(90100);
});

it('calculates monthly payment for a zero interest loan', function () {
    // $120,000 loan at 0% for 10 years (120 months) = $1000/month
    $payment = $this->service->calculateMonthlyPayment(12000000, 0, 120);

    expect($payment)->toBe(10000_0);
});

it('returns zero payment for zero term', function () {
    $payment = $this->service->calculateMonthlyPayment(20000000, 3.5, 0);

    expect($payment)->toBe(0);
});

it('calculates remaining balance after payments', function () {
    // $200,000 at 3.5% for 360 months, after 12 payments
    $remaining = $this->service->calculateRemainingBalance(20000000, 3.5, 360, 12);

    // After 1 year, should still owe most of the principal
    expect($remaining)->toBeGreaterThan(19500000)
        ->toBeLessThan(19800000);
});

it('returns zero balance when all payments made', function () {
    $remaining = $this->service->calculateRemainingBalance(20000000, 3.5, 360, 360);

    expect($remaining)->toBe(0);
});

it('returns zero balance when payments exceed term', function () {
    $remaining = $this->service->calculateRemainingBalance(20000000, 3.5, 360, 400);

    expect($remaining)->toBe(0);
});

it('returns full principal when no payments made', function () {
    $remaining = $this->service->calculateRemainingBalance(20000000, 3.5, 360, 0);

    expect($remaining)->toBe(20000000);
});

it('calculates remaining balance for zero interest loan', function () {
    // $120,000 at 0% for 120 months, after 60 payments = $60,000 remaining
    $remaining = $this->service->calculateRemainingBalance(12000000, 0, 120, 60);

    expect($remaining)->toBe(6000000);
});

it('projects future balances from a known balance point', function () {
    $projection = $this->service->projectFromBalance(
        20000000,
        now()->startOfMonth(),
        3.5,
        360,
        6,
    );

    expect($projection)->toHaveCount(6);

    // Each subsequent month should have a lower balance
    $values = array_values($projection);
    for ($i = 1; $i < count($values); $i++) {
        expect($values[$i])->toBeLessThan($values[$i - 1]);
    }
});

it('limits projection to remaining months when fewer than requested', function () {
    $projection = $this->service->projectFromBalance(
        100000,
        now()->startOfMonth(),
        3.5,
        3,
        12,
    );

    expect($projection)->toHaveCount(3);
});

// Tests for calculateRemainingMonths and getBalanceAtDate are in
// tests/Feature/LoanTest.php because they require Eloquent model
// instantiation which needs a database connection (HasUuids trait).
