<?php

use App\Models\User;

it('keeps the answers the onboarding collected', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)
        ->post('/onboarding/answers', [
            'goal' => 'understand',
            'today' => 'spreadsheet',
            'spending_guess' => 120000,
        ])
        ->assertRedirect();

    expect($user->refresh()->onboarding_answers)->toBe([
        'goal' => 'understand',
        'today' => 'spreadsheet',
        'spending_guess' => 120000,
    ]);
});

// Each question saves as it is answered, so a later one must not wipe the
// answers already given.
it('merges a later answer into the ones already stored', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'onboarding_answers' => ['goal' => 'debt'],
    ]);

    $this->actingAs($user)
        ->post('/onboarding/answers', ['spending_guess' => 90000])
        ->assertRedirect();

    expect($user->refresh()->onboarding_answers)->toBe([
        'goal' => 'debt',
        'spending_guess' => 90000,
    ]);
});

it('rejects an answer the onboarding does not offer', function (array $answer, string $field) {
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)
        ->post('/onboarding/answers', $answer)
        ->assertSessionHasErrors($field);

    expect($user->refresh()->onboarding_answers)->toBeNull();
})->with([
    'a goal that is not one of the options offered' => [['goal' => 'something-else'], 'goal'],
    'a spending guess that is not a whole amount' => [['spending_guess' => 'a lot'], 'spending_guess'],
]);
