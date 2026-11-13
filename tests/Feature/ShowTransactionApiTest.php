<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;

it('answers one of the user\'s transactions as the transactions table holds it', function () {
    $user = User::factory()->create();
    $bank = Bank::factory()->create(['name' => 'Panigale Bank']);
    $account = Account::factory()->for($user)->for($bank)->create();
    $category = Category::factory()->for($user)->create(['name' => 'Moto']);
    $label = Label::factory()->for($user)->create(['name' => 'Panigale']);
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $category->id,
        'description' => 'Pago en Ducati',
        'notes' => 'Revisión',
        'amount' => -12000,
    ]);
    $transaction->labels()->attach($label);

    $this->actingAs($user)
        ->getJson("/api/transactions/{$transaction->id}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.id', $transaction->id)
        ->assertJsonPath('data.amount', -12000)
        ->assertJsonPath('data.notes', 'Revisión')
        ->assertJsonPath('data.account.id', $account->id)
        ->assertJsonPath('data.account.bank.name', 'Panigale Bank')
        ->assertJsonPath('data.category.name', 'Moto')
        ->assertJsonPath('data.labels.0.id', $label->id)
        ->assertJsonPath('data.ai_categorized', false);
});

it('carries the siblings of a split part', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $original = Transaction::factory()->for($user)->for($account)->create(['amount' => -5000]);
    $part = Transaction::factory()->for($user)->for($account)->create([
        'amount' => -3000,
        'split_parent_id' => $original->id,
    ]);
    Transaction::factory()->for($user)->for($account)->create([
        'amount' => -2000,
        'split_parent_id' => $original->id,
    ]);

    $this->actingAs($user)
        ->getJson("/api/transactions/{$part->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.split_siblings');
});

it('does not answer another user\'s transaction', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner)
        ->for(Account::factory()->for($owner))
        ->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/transactions/{$transaction->id}")
        ->assertForbidden();
});

it('needs a session', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($user)
        ->for(Account::factory()->for($user))
        ->create();

    $this->getJson("/api/transactions/{$transaction->id}")->assertUnauthorized();
});
