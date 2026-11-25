<?php

use App\Http\Resources\V1\TransactionResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Http\Request;

it('nests the split lines under the transaction, in minor units', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food']);
    $transaction = Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => -1_000]);
    $line = TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $category->id, 'amount' => -600, 'position' => 0]);

    $row = (new TransactionResource($transaction))->toArray(new Request);

    expect($row['splits'])->toBe([[
        'id' => $line->id,
        'category' => ['id' => $category->id, 'name' => 'Food'],
        'amount' => -600,
        'position' => 0,
    ]])->and($row['split_parent_id'])->toBeNull();
});

it('writes nothing of a field still encrypted on the device', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'YWNjb3VudA==', 'name_iv' => 'abcdefghijklmnop']);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'ZGVzY3JpcHRpb24=',
        'description_iv' => 'abcdefghijklmnop',
        'notes' => 'bm90ZXM=',
        'notes_iv' => 'abcdefghijklmnop',
    ]);

    $row = (new TransactionResource($transaction))->toArray(new Request);

    expect($row['description'])->toBe('')
        ->and($row['notes'])->toBe('')
        ->and($row['account'])->toBe(['id' => $account->id, 'name' => '']);
});

it('keeps a plain field and an empty note as they are', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create(['user_id' => $user->id, 'name' => 'Main'])->id,
        'description' => 'Market',
        'notes' => null,
    ]);

    $row = (new TransactionResource($transaction))->toArray(new Request);

    expect($row['description'])->toBe('Market')
        ->and($row['notes'])->toBeNull()
        ->and($row['account']['name'])->toBe('Main');
});
