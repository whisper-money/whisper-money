<?php

use App\Models\AiConsent;
use App\Models\User;

it('records a consent and reports it as active', function () {
    $user = User::factory()->create();

    expect($user->hasActiveAiConsent())->toBeFalse();

    $consent = $user->recordAiConsent();

    expect($consent->scope)->toBe(AiConsent::SCOPE_FINANCE)
        ->and($consent->version)->toBe((string) config('ai_suggestions.consent_version'))
        ->and($consent->accepted_at)->not->toBeNull()
        ->and($user->hasActiveAiConsent())->toBeTrue();
});

it('does not duplicate consent rows for the same version', function () {
    $user = User::factory()->create();

    $user->recordAiConsent();
    $user->recordAiConsent();

    expect($user->aiConsents()->count())->toBe(1);
});

it('revokes an active consent', function () {
    $user = User::factory()->create();
    $user->recordAiConsent();

    $user->revokeAiConsent();

    expect($user->hasActiveAiConsent())->toBeFalse()
        ->and($user->aiConsents()->first()->revoked_at)->not->toBeNull();
});

it('treats consent from a previous version as inactive', function () {
    $user = User::factory()->create();
    AiConsent::factory()->for($user)->create(['version' => 'legacy-0']);

    expect($user->hasActiveAiConsent())->toBeFalse();
});
