<?php

use App\Models\Account;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;

it('can fetch user transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transactions = Transaction::factory()->count(3)->for($user)->for($account)->create();

    $response = $this->actingAs($user)->getJson('/api/sync/transactions');

    $response->assertSuccessful()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'user_id',
                    'account_id',
                    'category_id',
                    'description',
                    'description_iv',
                    'transaction_date',
                    'amount',
                    'currency_code',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
});

it('only returns user own transactions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Transaction::factory()->for($user)->for($account)->create();
    Transaction::factory()->for($otherUser)->for($otherAccount)->create();

    $response = $this->actingAs($user)->getJson('/api/sync/transactions');

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data');
});

it('can filter transactions by updated_at for delta sync', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $oldTransaction = Transaction::factory()->for($user)->for($account)->create([
        'updated_at' => now()->subDays(2),
    ]);

    $newTransaction = Transaction::factory()->for($user)->for($account)->create([
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/sync/transactions?since='.now()->subDay()->toISOString());

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newTransaction->id);
});

it('includes labels with id, name, and color', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $label1 = Label::factory()->create(['user_id' => $user->id, 'name' => 'Important', 'color' => '#ff0000']);
    $label2 = Label::factory()->create(['user_id' => $user->id, 'name' => 'Work', 'color' => '#00ff00']);

    $transaction = Transaction::factory()->for($user)->for($account)->create();
    $transaction->labels()->attach([$label1->id, $label2->id]);

    $response = $this->actingAs($user)->getJson('/api/sync/transactions');

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'labels' => [
                        '*' => ['id', 'name', 'color'],
                    ],
                ],
            ],
        ])
        ->assertJsonPath('data.0.labels.0.name', 'Important')
        ->assertJsonPath('data.0.labels.0.color', '#ff0000')
        ->assertJsonPath('data.0.labels.1.name', 'Work')
        ->assertJsonPath('data.0.labels.1.color', '#00ff00');
});
