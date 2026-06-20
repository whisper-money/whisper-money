<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('encrypted transactions endpoint returns only encrypted transactions', function () {
    $encrypted = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description_iv' => 'some-iv',
    ]);
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson('/api/transactions?encrypted=true');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($encrypted->id);
});

test('encrypted transactions endpoint paginates correctly', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->count(150)->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description_iv' => 'some-iv',
    ]);

    $response = $this->getJson('/api/transactions?encrypted=true');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(100);
    expect($response->json('next_page_url'))->not->toBeNull();

    $response2 = $this->getJson('/api/transactions?encrypted=true&page=2');
    expect($response2->json('data'))->toHaveCount(50);
    expect($response2->json('next_page_url'))->toBeNull();
});

test('encrypted transactions endpoint is scoped to authenticated user', function () {
    $otherUser = User::factory()->create();

    Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'description_iv' => 'some-iv',
    ]);
    $myTransaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description_iv' => 'some-iv',
    ]);

    $response = $this->getJson('/api/transactions?encrypted=true');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($myTransaction->id);
});

test('bulk update clears IVs and sets plaintext values', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'encrypted-desc',
        'description_iv' => 'some-iv',
        'notes' => 'encrypted-notes',
        'notes_iv' => 'some-notes-iv',
    ]);

    $response = $this->patchJson('/api/transactions/bulk', [
        'transactions' => [
            [
                'id' => $transaction->id,
                'description' => 'Decrypted description',
                'notes' => 'Decrypted notes',
                'description_iv' => null,
                'notes_iv' => null,
            ],
        ],
    ]);

    $response->assertOk();

    $transaction->refresh();
    expect($transaction->description)->toBe('Decrypted description');
    expect($transaction->notes)->toBe('Decrypted notes');
    expect($transaction->description_iv)->toBeNull();
    expect($transaction->notes_iv)->toBeNull();
});

test('bulk update rejects other user transactions', function () {
    $otherUser = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'description_iv' => 'some-iv',
    ]);

    $response = $this->patchJson('/api/transactions/bulk', [
        'transactions' => [
            [
                'id' => $transaction->id,
                'description' => 'Stolen data',
                'description_iv' => null,
                'notes_iv' => null,
            ],
        ],
    ]);

    $response->assertForbidden();
});

test('bulk update validates max batch size', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $transactions = Transaction::factory()->count(51)->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description_iv' => 'some-iv',
    ]);

    $payload = $transactions->map(fn ($t) => [
        'id' => $t->id,
        'description' => 'decrypted',
        'description_iv' => null,
        'notes_iv' => null,
    ])->toArray();

    $response = $this->patchJson('/api/transactions/bulk', [
        'transactions' => $payload,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('transactions');
});

test('bulk update validates required fields', function () {
    $response = $this->patchJson('/api/transactions/bulk', [
        'transactions' => [
            ['description' => 'missing id'],
        ],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('transactions.0.id');
});

test('bulk update handles nullable notes correctly', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'encrypted-desc',
        'description_iv' => 'some-iv',
        'notes' => null,
        'notes_iv' => null,
    ]);

    $response = $this->patchJson('/api/transactions/bulk', [
        'transactions' => [
            [
                'id' => $transaction->id,
                'description' => 'Decrypted description',
                'notes' => null,
                'description_iv' => null,
                'notes_iv' => null,
            ],
        ],
    ]);

    $response->assertOk();

    $transaction->refresh();
    expect($transaction->description)->toBe('Decrypted description');
    expect($transaction->notes)->toBeNull();
    expect($transaction->description_iv)->toBeNull();
    expect($transaction->notes_iv)->toBeNull();
});

test('guests cannot access transactions API', function () {
    auth()->logout();

    $this->getJson('/api/transactions')->assertUnauthorized();
    $this->patchJson('/api/transactions/bulk', ['transactions' => []])->assertUnauthorized();
});

test('bulk update does not fire model events', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description_iv' => 'some-iv',
    ]);

    $originalUpdatedAt = $transaction->updated_at;

    $this->patchJson('/api/transactions/bulk', [
        'transactions' => [
            [
                'id' => $transaction->id,
                'description' => 'Decrypted',
                'description_iv' => null,
                'notes_iv' => null,
            ],
        ],
    ])->assertOk();

    $transaction->refresh();
    expect($transaction->updated_at->toDateTimeString())->toBe($originalUpdatedAt->toDateTimeString());
});
