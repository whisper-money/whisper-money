<?php

use App\Models\AiConsent;
use App\Models\User;

use function Pest\Laravel\actingAs;

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

it('dismisses the consent prompt idempotently', function () {
    $user = User::factory()->create();

    expect($user->hasDismissedAiConsentPrompt())->toBeFalse();

    $user->dismissAiConsentPrompt();
    $dismissedAt = $user->ai_consent_prompt_dismissed_at;

    $user->dismissAiConsentPrompt();

    expect($user->hasDismissedAiConsentPrompt())->toBeTrue()
        ->and($user->ai_consent_prompt_dismissed_at->equalTo($dismissedAt))->toBeTrue();
});

it('marks the prompt as dismissed when consent is granted', function () {
    $user = User::factory()->create();

    actingAs($user)->postJson(route('ai.consent.store'))->assertOk();

    expect($user->refresh()->hasDismissedAiConsentPrompt())->toBeTrue()
        ->and($user->hasActiveAiConsent())->toBeTrue();
});

it('dismisses the prompt without granting consent', function () {
    $user = User::factory()->create();

    actingAs($user)->postJson(route('ai.consent.dismiss'))
        ->assertOk()
        ->assertJson(['dismissed' => true]);

    expect($user->refresh()->hasDismissedAiConsentPrompt())->toBeTrue()
        ->and($user->hasActiveAiConsent())->toBeFalse();
});
