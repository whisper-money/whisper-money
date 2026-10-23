<?php

use App\Models\User;
use App\Services\Ai\AiImportMappingGate;

beforeEach(function () {
    config()->set('ai_import_mapping.enabled', true);
    config()->set('ai_import_mapping.free_users_enabled', true);
    config()->set('services.typesafe.enabled', true);
    config()->set('subscriptions.enabled', true);
});

it('allows a free user while free users are let in', function () {
    expect(app(AiImportMappingGate::class)->allows(User::factory()->create()))->toBeTrue();
});

it('allows a pro user without AI consent', function () {
    config()->set('ai_import_mapping.free_users_enabled', false);
    $user = User::factory()->subscribed()->create();

    expect($user->hasActiveAiConsent())->toBeFalse()
        ->and(app(AiImportMappingGate::class)->allows($user))->toBeTrue();
});

it('denies a free user once free users are left out', function () {
    config()->set('ai_import_mapping.free_users_enabled', false);

    expect(app(AiImportMappingGate::class)->allows(User::factory()->create()))->toBeFalse();
});

it('denies everyone when the master switch is off', function () {
    config()->set('ai_import_mapping.enabled', false);

    expect(app(AiImportMappingGate::class)->allows(User::factory()->subscribed()->create()))->toBeFalse();
});

it('denies everyone without a TypeSafe key', function () {
    config()->set('services.typesafe.enabled', false);

    expect(app(AiImportMappingGate::class)->allows(User::factory()->subscribed()->create()))->toBeFalse();
});
