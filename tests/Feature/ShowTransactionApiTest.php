<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Str;

it('answers one of the user\'s transactions as the transactions table holds it', function () {
    $user = User::factory()->create();
    $bank = Bank::factory()->create(['name' => 'Panigale Bank']);
    $account = Account::factory()->for($user)->for($bank)->create();
    $category = Category::factory()->for($user)->create(['name' => 'Moto']);
    $label = Label::factory()->for($user)->create(['name' => 'Panigale']);
    $transaction = Transaction::factory()->plaintext()->for($user)->for($account)->create([
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
        ->assertJsonPath('data.ai_categorized', false)
        ->assertJsonPath('data.is_split', false)
        ->assertJsonPath('data.splits', []);
});

it('carries the lines of a split transaction, each with its category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->create(['amount' => -5000, 'category_id' => null]);
    $fuel = Category::factory()->for($user)->create(['name' => 'Fuel']);
    $parts = Category::factory()->for($user)->create(['name' => 'Parts']);
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $fuel->id, 'amount' => -3000, 'position' => 0]);
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $parts->id, 'amount' => -2000, 'position' => 1]);

    $this->actingAs($user)
        ->getJson("/api/transactions/{$transaction->id}")
        ->assertOk()
        ->assertJsonPath('data.amount', -5000)
        ->assertJsonPath('data.is_split', true)
        ->assertJsonPath('data.split_count', 2)
        ->assertJsonCount(2, 'data.splits')
        ->assertJsonPath('data.splits.0.category.name', 'Fuel')
        ->assertJsonPath('data.splits.1.amount', -2000);
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

it('does not answer a transaction another member owns in a shared space', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Space::factory()->create(['owner_id' => $owner->id]);
    $household->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $transaction = Transaction::factory()
        ->for($owner)
        ->for(Account::factory()->for($owner)->state(['space_id' => $household->id]))
        ->create(['space_id' => $household->id]);

    // The transactions page lists and edits only the user's own rows.
    $this->actingAs($member)
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
