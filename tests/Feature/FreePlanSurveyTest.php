<?php

use App\Enums\FreePlanSurveyReason;
use App\Models\FreePlanSurveyResponse;
use App\Models\User;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

test('stores why a user on the paywall stays on the free plan', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'too_expensive',
            'gate' => 'soft',
        ])
        ->assertNoContent();

    $response = $user->freePlanSurveyResponses()->sole();

    expect($response->reason)->toBe(FreePlanSurveyReason::TooExpensive)
        ->and($response->details)->toBeNull()
        ->and($response->gate)->toBe('soft');
});

test('keeps the details given with the other reason', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'other',
            'details' => '  I only track cash  ',
            'gate' => 'former-subscriber',
        ])
        ->assertNoContent();

    expect($user->freePlanSurveyResponses()->sole())
        ->reason->toBe(FreePlanSurveyReason::Other)
        ->details->toBe('I only track cash')
        ->gate->toBe('former-subscriber');
});

test('drops the details unless the reason is other', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'still_trying',
            'details' => 'left over from picking other first',
            'gate' => 'hard',
        ])
        ->assertNoContent();

    expect($user->freePlanSurveyResponses()->sole()->details)->toBeNull();
});

test('stores blank details on the other reason as null', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'other',
            'details' => '   ',
            'gate' => 'soft',
        ])
        ->assertNoContent();

    expect($user->freePlanSurveyResponses()->sole()->details)->toBeNull();
});

test('rejects an answer that is missing or invalid', function (array $payload, string $field) {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson(route('subscribe.free-plan.survey'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(FreePlanSurveyResponse::query()->count())->toBe(0);
})->with([
    'missing reason' => [['gate' => 'soft'], 'reason'],
    'unknown reason' => [['reason' => 'too_shiny', 'gate' => 'soft'], 'reason'],
    'details too long' => [['reason' => 'other', 'details' => str_repeat('a', 1001), 'gate' => 'soft'], 'details'],
    'missing gate' => [['reason' => 'other'], 'gate'],
    'unknown gate' => [['reason' => 'other', 'gate' => 'checkout'], 'gate'],
]);

test('accepts details right at the length cap', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'other',
            'details' => str_repeat('a', 1000),
            'gate' => 'soft',
        ])
        ->assertNoContent();
});

test('guests cannot answer the survey', function () {
    $this->post(route('subscribe.free-plan.survey'), [
        'reason' => 'too_expensive',
        'gate' => 'soft',
    ])->assertRedirect(route('register'));

    expect(FreePlanSurveyResponse::query()->count())->toBe(0);
});

test('a user on a paid plan cannot answer the survey', function () {
    $user = User::factory()->onboarded()->subscribed()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'too_expensive',
            'gate' => 'soft',
        ])
        ->assertForbidden();

    expect(FreePlanSurveyResponse::query()->count())->toBe(0);
});

test('the survey cannot be answered where subscriptions are off', function () {
    config(['subscriptions.enabled' => false]);

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'too_expensive',
            'gate' => 'soft',
        ])
        ->assertForbidden();
});

test('a user still held by the paywall can answer the survey', function () {
    // Onboarded, not subscribed, never past the paywall: every route behind
    // the `subscribed` middleware would send this user back to it.
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => null]);

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'use_too_little',
            'gate' => 'soft',
        ])
        ->assertNoContent();
});
