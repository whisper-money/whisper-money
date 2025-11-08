<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('guests cannot access transactions page', function () {
    $response = $this->get(route('transactions.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can access transactions page', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('categories')
        ->has('accounts')
        ->has('banks')
    );
});

test('users can update their own transaction category', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $category->id,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'category_id' => $category->id,
    ]);
});

test('users can update transaction notes', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'notes' => 'encrypted_notes_content',
        'notes_iv' => str_repeat('c', 16),
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'notes' => 'encrypted_notes_content',
        'notes_iv' => str_repeat('c', 16),
    ]);
});

test('users can clear transaction category', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => null,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'category_id' => null,
    ]);
});

test('users cannot update other users transactions', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $otherUser = User::factory()->create(['encryption_salt' => str_repeat('b', 24)]);
    $account = Account::factory()->create(['user_id' => $otherUser->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $category->id,
    ]);

    $response->assertForbidden();
});

test('category_id must exist when updating transaction', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => 99999,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['category_id']);
});

test('notes_iv must be exactly 16 characters', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'notes' => 'encrypted_notes',
        'notes_iv' => 'invalid',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['notes_iv']);
});

test('users can soft delete their own transactions', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->deleteJson(route('transactions.destroy', $transaction));

    $response->assertSuccessful();
    $this->assertSoftDeleted('transactions', [
        'id' => $transaction->id,
    ]);
});

test('users cannot delete other users transactions', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $otherUser = User::factory()->create(['encryption_salt' => str_repeat('b', 24)]);
    $account = Account::factory()->create(['user_id' => $otherUser->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->deleteJson(route('transactions.destroy', $transaction));

    $response->assertForbidden();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'deleted_at' => null,
    ]);
});

test('transactions index page passes user categories', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $otherUser = User::factory()->create();

    $userCategory = Category::factory()->create(['user_id' => $user->id, 'name' => 'My Category']);
    Category::factory()->create(['user_id' => $otherUser->id, 'name' => 'Other Category']);

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('categories', 1)
        ->where('categories.0.name', 'My Category')
    );
});

test('transactions index page passes user accounts', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $otherUser = User::factory()->create();

    Account::factory()->create(['user_id' => $user->id, 'name' => 'encrypted_name_1', 'name_iv' => str_repeat('a', 16)]);
    Account::factory()->create(['user_id' => $otherUser->id, 'name' => 'encrypted_name_2', 'name_iv' => str_repeat('b', 16)]);

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('accounts', 1)
    );
});
