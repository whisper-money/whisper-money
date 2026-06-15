<?php

use App\Enums\CategorySource;
use App\Models\Transaction;
use App\Models\User;

it('filters transactions to only AI-categorized ones', function () {
    $user = User::factory()->create();

    $ai = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_source' => CategorySource::Ai,
    ]);
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_source' => CategorySource::Manual,
    ]);
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_source' => null,
    ]);

    $results = Transaction::query()
        ->where('user_id', $user->id)
        ->applyFilters(['category_source' => 'ai'])
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->is($ai))->toBeTrue();
});
