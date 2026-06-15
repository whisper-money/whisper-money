<?php

use App\Enums\CategorySource;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

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

it('applies and echoes the AI filter through the index route', function () {
    $user = User::factory()->onboarded()->create();
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_source' => CategorySource::Ai,
    ]);
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_source' => CategorySource::Manual,
    ]);

    actingAs($user)
        ->get(route('transactions.index', ['category_source' => 'ai']))
        ->assertInertia(fn ($page) => $page
            ->where('appliedFilters.category_source', 'ai')
            ->has('transactions.data', 1),
        );
});
