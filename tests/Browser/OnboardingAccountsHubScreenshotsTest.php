<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;

/**
 * The two states step 6 has, captured at phone width. The step is one screen
 * that is returned to rather than a door chosen once, so both states are the
 * change — the PR is reviewed on what they look like rather than on the diff.
 */
it('captures the hub with nothing in it yet', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'currency_code' => 'EUR',
    ]);

    $this->actingAs($user);

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->wait(1)
        ->assertSee("Let's build the picture")
        ->assertSee('Connect a bank')
        ->assertSee('Add one myself')
        ->screenshot(filename: 'onboarding-accounts-empty')
        ->assertNoJavascriptErrors();
});

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
        ->wait(1)
        ->assertSee("3 in. What's missing?")
        ->assertSee('BBVA · syncing daily')
        ->assertSee('Usually missed')
        ->assertSee('A mortgage or a loan')
        ->assertSee('A pension or a broker')
        ->assertSee('Another bank')
        ->screenshot(filename: 'onboarding-accounts-full')
        ->assertNoJavascriptErrors();
});
