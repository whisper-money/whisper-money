<?php

use App\Enums\PlanFeature;
use App\Models\User;

test('every current feature requires a pro plan', function (PlanFeature $feature) {
    expect($feature->requiresProPlan())->toBeTrue();
})->with([
    [PlanFeature::ConnectedAccounts],
    [PlanFeature::AiSuggestions],
]);

test('every feature is available when subscriptions are disabled', function () {
    config()->set('subscriptions.enabled', false);
    $user = User::factory()->create();

    expect($user->canUseFeature(PlanFeature::ConnectedAccounts))->toBeTrue()
        ->and($user->canUseFeature(PlanFeature::AiSuggestions))->toBeTrue();
});

test('pro features are unavailable to free users when subscriptions are enabled', function () {
    config()->set('subscriptions.enabled', true);
    $user = User::factory()->create();

    expect($user->canUseFeature(PlanFeature::ConnectedAccounts))->toBeFalse()
        ->and($user->canUseFeature(PlanFeature::AiSuggestions))->toBeFalse();
});

test('pro features are available to subscribed users', function () {
    config()->set('subscriptions.enabled', true);
    $user = User::factory()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    expect($user->canUseFeature(PlanFeature::ConnectedAccounts))->toBeTrue()
        ->and($user->canUseFeature(PlanFeature::AiSuggestions))->toBeTrue();
});
