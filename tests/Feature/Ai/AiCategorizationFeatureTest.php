<?php

use App\Features\AiCategorization;
use App\Models\User;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config()->set('ai_categorization.rollout_after', '2026-06-13 21:00:00');
});

it('activates for users created after the rollout cutoff', function () {
    $user = User::factory()->create(['created_at' => '2026-06-13 21:00:01']);

    expect(Feature::for($user)->active(AiCategorization::class))->toBeTrue();
});

it('stays inactive for users created before the rollout cutoff', function () {
    $user = User::factory()->create(['created_at' => '2026-06-13 20:59:59']);

    expect(Feature::for($user)->active(AiCategorization::class))->toBeFalse();
});

it('is inactive when no rollout cutoff is configured', function () {
    config()->set('ai_categorization.rollout_after', null);

    $user = User::factory()->create(['created_at' => '2026-06-14 00:00:00']);

    expect(Feature::for($user)->active(AiCategorization::class))->toBeFalse();
});
