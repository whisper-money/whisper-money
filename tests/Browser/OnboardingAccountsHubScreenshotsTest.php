<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;

/**
 * Step 6 once something is in it, captured at phone width. The empty hub is
 * asserted by OnboardingFlowTest's deep-link test.
 */
it('captures the hub asking for what the bank never returns', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'currency_code' => 'EUR',
    ]);

    $bank = Bank::factory()->create(['name' => 'BBVA']);
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    foreach ([
        ['Cuenta Nómina', 'checking'],
        ['Tarjeta Crédito', 'credit_card'],
        ['Cuenta Ahorro', 'savings'],
    ] as [$name, $type]) {
        Account::factory()->create([
            'user_id' => $user->id,
            'bank_id' => $bank->id,
            'banking_connection_id' => $connection->id,
            'name' => $name,
            'type' => $type,
            'currency_code' => 'EUR',
        ]);
    }

    $this->actingAs($user);

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->assertSee("3 in. What's missing?")
        ->assertSee('BBVA · syncing daily')
        ->assertSee('Usually missed')
        ->assertSee('A mortgage or a loan')
        ->assertSee('A pension or a broker')
        ->assertSee('Another bank')
        ->screenshot(filename: 'onboarding-accounts-full')
        ->assertNoJavascriptErrors();
});
