<?php

test('warns when there are no subscriptions', function () {
    bindMockStripeClientForStats(['active' => [], 'trialing' => []]);

    $this->artisan('stripe:subscription-stats')
        ->expectsOutputToContain('No active or trialing subscriptions found')
        ->assertSuccessful();
});

test('reports counts and current/projected MRR and ARR', function () {
    bindMockStripeClientForStats([
        'active' => [
            makeStripeSubscription('eur', 399, 'month'),
            makeStripeSubscription('eur', 2388, 'year'),
        ],
        'trialing' => [
            makeStripeSubscription('eur', 399, 'month'),
        ],
    ]);

    // current MRR = 3.99 + (23.88/12 = 1.99) = 5.98 ; ARR = 71.76
    // projected MRR = 5.98 + 3.99 = 9.97 ; ARR = 119.64
    $this->artisan('stripe:subscription-stats')
        ->expectsOutputToContain('Active subs:')
        ->expectsOutputToContain('Trialing subs:')
        ->expectsOutputToContain('€5.98')
        ->expectsOutputToContain('€71.76')
        ->expectsOutputToContain('€9.97')
        ->expectsOutputToContain('€119.64')
        ->assertSuccessful();
});

test('groups stats per currency', function () {
    bindMockStripeClientForStats([
        'active' => [
            makeStripeSubscription('eur', 1000, 'month'),
            makeStripeSubscription('brl', 5000, 'month'),
        ],
        'trialing' => [],
    ]);

    $this->artisan('stripe:subscription-stats')
        ->expectsOutputToContain('EUR')
        ->expectsOutputToContain('€10.00')
        ->expectsOutputToContain('BRL')
        ->expectsOutputToContain('R$50.00')
        ->assertSuccessful();
});

test('accounts for quantity in MRR', function () {
    bindMockStripeClientForStats([
        'active' => [
            makeStripeSubscription('eur', 1000, 'month', quantity: 3),
        ],
        'trialing' => [],
    ]);

    $this->artisan('stripe:subscription-stats')
        ->expectsOutputToContain('€30.00')
        ->assertSuccessful();
});
