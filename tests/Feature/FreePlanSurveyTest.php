<?php

use App\Enums\FreePlanSurveyReason;
use App\Enums\PaywallGate;
use App\Models\BankingConnection;
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
        ])
        ->assertNoContent();

    $response = $user->freePlanSurveyResponses()->sole();

    expect($response->reason)->toBe(FreePlanSurveyReason::TooExpensive)
        ->and($response->details)->toBeNull()
        ->and($response->gate)->toBe(PaywallGate::Soft);
});

test('keeps the details given with the other reason', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'other',
            'details' => '  I only track cash  ',
        ])
        ->assertNoContent();

    expect($user->freePlanSurveyResponses()->sole())
        ->reason->toBe(FreePlanSurveyReason::Other)
        ->details->toBe('I only track cash');
});

test('drops the details unless the reason is other', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'still_trying',
            'details' => 'left over from picking other first',
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
    'missing reason' => [[], 'reason'],
    'unknown reason' => [['reason' => 'too_shiny'], 'reason'],
    'details too long' => [['reason' => 'other', 'details' => str_repeat('a', 1001)], 'details'],
]);

test('accepts details right at the length cap', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'other',
            'details' => str_repeat('a', 1000),
        ])
        ->assertNoContent();
});

test('guests cannot answer the survey', function () {
    $this->post(route('subscribe.free-plan.survey'), [
        'reason' => 'too_expensive',
    ])->assertRedirect(route('register'));

    expect(FreePlanSurveyResponse::query()->count())->toBe(0);
});

test('a user on a paid plan cannot answer the survey', function () {
    $user = User::factory()->onboarded()->subscribed()->create();

    $this->actingAs($user)
        ->post(route('subscribe.free-plan.survey'), [
            'reason' => 'too_expensive',
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
        ])
        ->assertNoContent();
});

test('the survey cannot be flooded with answers', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    foreach (range(1, 6) as $attempt) {
        $this->post(route('subscribe.free-plan.survey'), [
            'reason' => 'too_expensive',
        ])->assertNoContent();
    }

    $this->post(route('subscribe.free-plan.survey'), [
        'reason' => 'too_expensive',
    ])->assertTooManyRequests();

    expect($user->freePlanSurveyResponses()->count())->toBe(6);
});

describe('the gate recorded with an answer', function () {
    // The gate is read off the user, so a page that sends the wrong one, or an
    // old one cached from before, cannot change what is stored.
    test('ignores the gate the page sends', function () {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->post(route('subscribe.free-plan.survey'), ['reason' => 'too_expensive', 'gate' => 'hard'])
            ->assertNoContent();

        expect($user->freePlanSurveyResponses()->sole()->gate)->toBe(PaywallGate::Soft);
    });

    test('records a user who never paid and has nothing to give up as soft', function () {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->post(route('subscribe.free-plan.survey'), ['reason' => 'too_expensive'])
            ->assertNoContent();

        expect($user->freePlanSurveyResponses()->sole()->gate)->toBe(PaywallGate::Soft);
    });

    test('records a user who never paid but holds a bank as hard', function () {
        $user = User::factory()->onboarded()->create();
        BankingConnection::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('subscribe.free-plan.survey'), ['reason' => 'too_expensive'])
            ->assertNoContent();

        expect($user->freePlanSurveyResponses()->sole()->gate)->toBe(PaywallGate::Hard);
    });

    test('records a former subscriber whatever they still hold', function (Closure $holdings) {
        $user = User::factory()->onboarded()->formerSubscriber()->create();
        $holdings($user);

        $this->actingAs($user)
            ->post(route('subscribe.free-plan.survey'), ['reason' => 'too_expensive', 'gate' => 'hard'])
            ->assertNoContent();

        expect($user->freePlanSurveyResponses()->sole()->gate)->toBe(PaywallGate::FormerSubscriber);
    })->with([
        'banks still connected' => [fn () => fn (User $user) => BankingConnection::factory()->for($user)->create()],
        // The prod case: every bank removed by hand, so the free-plan screen
        // only had the AI to switch off and asked as the hard gate.
        'banks removed, AI still on' => [fn () => function (User $user) {
            BankingConnection::factory()->for($user)->create()->delete();
            $user->recordAiConsent();
        }],
        'nothing left' => [fn () => fn (User $user) => null],
    ]);
});
