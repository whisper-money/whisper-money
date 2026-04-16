<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget('popular-banks');
});

test('home popular banks are ordered by popularity and then Spain first', function () {
    $user = User::factory()->create();

    $mostPopularNonSpanish = Bank::factory()->create([
        'name' => 'Apex Banque',
        'logo' => 'https://example.com/apex.png',
    ]);
    $spanishTie = Bank::factory()->create([
        'name' => 'Zeta Espana Bank',
        'logo' => 'https://example.com/zeta.png',
    ]);
    $nonSpanishTie = Bank::factory()->create([
        'name' => 'Alpha Deutsche Bank',
        'logo' => 'https://example.com/alpha.png',
    ]);

    $frenchConnection = BankingConnection::factory()->for($user)->create([
        'aspsp_name' => $mostPopularNonSpanish->name,
        'aspsp_country' => 'FR',
    ]);
    Account::factory()->count(3)
        ->for($user)
        ->for($mostPopularNonSpanish)
        ->for($frenchConnection, 'bankingConnection')
        ->create();

    $spanishConnection = BankingConnection::factory()->for($user)->create([
        'aspsp_name' => $spanishTie->name,
        'aspsp_country' => 'ES',
    ]);
    Account::factory()->count(2)
        ->for($user)
        ->for($spanishTie)
        ->for($spanishConnection, 'bankingConnection')
        ->create();

    $germanConnection = BankingConnection::factory()->for($user)->create([
        'aspsp_name' => $nonSpanishTie->name,
        'aspsp_country' => 'DE',
    ]);
    Account::factory()->count(2)
        ->for($user)
        ->for($nonSpanishTie)
        ->for($germanConnection, 'bankingConnection')
        ->create();

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->where('popularBanks.0.name', $mostPopularNonSpanish->name)
            ->where('popularBanks.1.name', $spanishTie->name)
            ->where('popularBanks.2.name', $nonSpanishTie->name)
        );
});

test('home returns popular banks for unauthenticated visitors', function () {
    $bank = Bank::factory()->create([
        'name' => 'Public Bank',
        'logo' => 'https://example.com/public.png',
        'user_id' => null,
    ]);
    $connection = BankingConnection::factory()->create([
        'aspsp_name' => $bank->name,
        'aspsp_country' => 'ES',
    ]);
    Account::factory()->for($bank)->for($connection, 'bankingConnection')->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->where('popularBanks.0.name', $bank->name)
        );
});
