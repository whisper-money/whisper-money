<?php

use App\Models\User;
use App\Services\Ai\AiCategorizationGate;

function eligibleUser(): User
{
    $user = User::factory()->create();
    $user->recordAiConsent();

    return $user;
}

it('allows an enabled, pro, consented user', function () {
    expect(app(AiCategorizationGate::class)->allows(eligibleUser()))->toBeTrue();
});

it('denies when the master kill switch is off', function () {
    config()->set('ai_categorization.enabled', false);

    expect(app(AiCategorizationGate::class)->allows(eligibleUser()))->toBeFalse();
});

it('denies a user without active AI consent', function () {
    $user = User::factory()->create();

    expect(app(AiCategorizationGate::class)->allows($user))->toBeFalse();
});

it('denies a non-pro user when subscriptions are enforced', function () {
    config()->set('subscriptions.enabled', true);

    expect(app(AiCategorizationGate::class)->allows(eligibleUser()))->toBeFalse();
});
