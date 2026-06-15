<?php

use App\Features\AiCategorization;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use Laravel\Pennant\Feature;

function eligibleUser(): User
{
    $user = User::factory()->create();
    $user->recordAiConsent();
    Feature::for($user)->activate(AiCategorization::class);

    return $user;
}

it('allows an enabled, pro, consented, flagged user', function () {
    expect(app(AiCategorizationGate::class)->allows(eligibleUser()))->toBeTrue();
});

it('denies when the master kill switch is off', function () {
    config()->set('ai_categorization.enabled', false);

    expect(app(AiCategorizationGate::class)->allows(eligibleUser()))->toBeFalse();
});

it('denies a user without active AI consent', function () {
    $user = User::factory()->create();
    Feature::for($user)->activate(AiCategorization::class);

    expect(app(AiCategorizationGate::class)->allows($user))->toBeFalse();
});

it('denies a user the rollout flag is not active for', function () {
    config()->set('ai_categorization.rollout_after', '2026-06-13 21:00:00');

    // Signed up before the rollout cutoff, so the feature does not resolve on.
    $user = User::factory()->create(['created_at' => '2026-06-13 20:00:00']);
    $user->recordAiConsent();

    expect(app(AiCategorizationGate::class)->allows($user))->toBeFalse();
});

it('denies a non-pro user when subscriptions are enforced', function () {
    config()->set('subscriptions.enabled', true);

    expect(app(AiCategorizationGate::class)->allows(eligibleUser()))->toBeFalse();
});
