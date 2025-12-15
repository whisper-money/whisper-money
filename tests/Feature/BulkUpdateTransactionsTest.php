<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
});

it('can bulk update transactions by IDs', function () {
    $transactions = Transaction::factory()
        ->count(3)
        ->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => null,
        ]);

    $newCategory = Category::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'transaction_ids' => $transactions->pluck('id')->toArray(),
        'category_id' => $newCategory->id,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'Transactions updated successfully',
        'count' => 3,
    ]);

    foreach ($transactions as $transaction) {
        expect($transaction->fresh()->category_id)->toBe($newCategory->id);
    }
});

it('can bulk update transactions by filters with date range', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2024-01-15',
        'category_id' => null,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2024-01-20',
        'category_id' => null,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2024-02-01',
        'category_id' => null,
    ]);

    $newCategory = Category::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'filters' => [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
        ],
        'category_id' => $newCategory->id,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'Transactions updated successfully',
        'count' => 2,
    ]);

    $januaryTransactions = Transaction::query()
        ->where('user_id', $this->user->id)
        ->whereDate('transaction_date', '>=', '2024-01-01')
        ->whereDate('transaction_date', '<=', '2024-01-31')
        ->get();

    foreach ($januaryTransactions as $transaction) {
        expect($transaction->category_id)->toBe($newCategory->id);
    }

    $februaryTransaction = Transaction::query()
        ->where('user_id', $this->user->id)
        ->whereDate('transaction_date', '2024-02-01')
        ->first();

    expect($februaryTransaction->category_id)->toBeNull();
});

it('can bulk update transactions by filters with amount range', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => '5000',
        'category_id' => null,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => '15000',
        'category_id' => null,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => '25000',
        'category_id' => null,
    ]);

    $newCategory = Category::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'filters' => [
            'amount_min' => 100,
            'amount_max' => 200,
        ],
        'category_id' => $newCategory->id,
    ]);

    $response->assertSuccessful();

    $midRangeTransaction = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('amount', '15000')
        ->first();

    expect($midRangeTransaction->category_id)->toBe($newCategory->id);

    $lowTransaction = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('amount', '5000')
        ->first();

    expect($lowTransaction->category_id)->toBeNull();

    $highTransaction = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('amount', '25000')
        ->first();

    expect($highTransaction->category_id)->toBeNull();
});

it('can bulk update transactions by filters with category filter', function () {
    $categoryA = Category::factory()->create(['user_id' => $this->user->id]);
    $categoryB = Category::factory()->create(['user_id' => $this->user->id]);
    $categoryC = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $categoryA->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $categoryA->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $categoryB->id,
    ]);

    $response = $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'filters' => [
            'category_ids' => [$categoryA->id],
        ],
        'category_id' => $categoryC->id,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'count' => 2,
    ]);

    $updatedTransactions = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('category_id', $categoryC->id)
        ->count();

    expect($updatedTransactions)->toBe(2);

    $unchangedTransaction = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('category_id', $categoryB->id)
        ->count();

    expect($unchangedTransaction)->toBe(1);
});

it('can bulk update transactions with labels by IDs', function () {
    $transactions = Transaction::factory()
        ->count(2)
        ->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
        ]);

    $label1 = Label::factory()->create(['user_id' => $this->user->id]);
    $label2 = Label::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'transaction_ids' => $transactions->pluck('id')->toArray(),
        'label_ids' => [$label1->id, $label2->id],
    ]);

    $response->assertSuccessful();

    foreach ($transactions as $transaction) {
        $labelIds = $transaction->fresh()->labels->pluck('id')->toArray();
        expect($labelIds)->toContain($label1->id);
        expect($labelIds)->toContain($label2->id);
    }
});

it('can update all transactions when no filters or IDs are provided', function () {
    Transaction::factory()
        ->count(3)
        ->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => null,
        ]);

    $newCategory = Category::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'category_id' => $newCategory->id,
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'Transactions updated successfully',
        'count' => 3,
    ]);

    $allTransactions = Transaction::query()
        ->where('user_id', $this->user->id)
        ->get();

    foreach ($allTransactions as $transaction) {
        expect($transaction->category_id)->toBe($newCategory->id);
    }
});

it('validates that at least one update field is provided', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    $response = $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'transaction_ids' => [$transaction->id],
    ]);

    $response->assertStatus(400);
    $response->assertJson([
        'message' => 'No update data provided.',
    ]);
});

it('only updates transactions belonging to the authenticated user', function () {
    $otherUser = User::factory()->create([
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);
    $otherAccount = Account::factory()->create(['user_id' => $otherUser->id]);

    $myTransaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $otherTransaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $otherAccount->id,
        'category_id' => null,
    ]);

    $newCategory = Category::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'transaction_ids' => [$myTransaction->id, $otherTransaction->id],
        'category_id' => $newCategory->id,
    ]);

    $response->assertStatus(403);

    expect($myTransaction->fresh()->category_id)->toBeNull();
    expect($otherTransaction->fresh()->category_id)->toBeNull();
});
