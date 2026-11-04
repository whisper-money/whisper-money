<?php

use App\Models\FreePlanSurveyResponse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * @return array<string, mixed>
 */
function convertedWebhookPayload(string $stripeId, string $status, string $type = 'customer.subscription.created', ?string $previousStatus = null): array
{
    return [
        'type' => $type,
        'data' => [
            'object' => ['id' => $stripeId, 'status' => $status],
            'previous_attributes' => $previousStatus === null ? [] : ['status' => $previousStatus],
        ],
    ];
}

function subscriptionFor(User $user, string $status): string
{
    return $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_converted_'.fake()->unique()->numerify('######'),
        'stripe_status' => $status,
        'stripe_price' => 'price_123',
    ])->stripe_id;
}

test('stamps the answers of a user who starts a trial or a paid plan', function (string $status) {
    $this->freezeSecond();
    $user = User::factory()->create();
    $responses = FreePlanSurveyResponse::factory()->for($user)->count(2)->create();
    $stripeId = subscriptionFor($user, $status);

    event(new WebhookHandled(convertedWebhookPayload($stripeId, $status)));

    $responses->each(fn (FreePlanSurveyResponse $response) => expect($response->fresh()->converted_at)
        ->toEqual(now()));
})->with(['trialing', 'active']);

test('stamps the answers once a checkout that needed 3-D Secure turns active', function () {
    $user = User::factory()->create();
    $response = FreePlanSurveyResponse::factory()->for($user)->create();
    $stripeId = subscriptionFor($user, 'incomplete');

    event(new WebhookHandled(convertedWebhookPayload($stripeId, 'incomplete')));
    expect($response->fresh()->converted_at)->toBeNull();

    event(new WebhookHandled(convertedWebhookPayload($stripeId, 'active', 'customer.subscription.updated', 'incomplete')));
    expect($response->fresh()->converted_at)->not->toBeNull();
});

test('keeps the date of an answer already stamped', function () {
    $user = User::factory()->create();
    $convertedAt = Carbon::parse('2026-10-01 10:00:00');
    $response = FreePlanSurveyResponse::factory()->for($user)->create(['converted_at' => $convertedAt]);
    $stripeId = subscriptionFor($user, 'active');

    event(new WebhookHandled(convertedWebhookPayload($stripeId, 'active', 'customer.subscription.updated', 'trialing')));

    expect($response->fresh()->converted_at)->toEqual($convertedAt);
});

test('leaves the answers alone for anything but a subscription starting', function (string $type, string $status, ?string $previousStatus = null) {
    $user = User::factory()->create();
    $response = FreePlanSurveyResponse::factory()->for($user)->create();
    $stripeId = subscriptionFor($user, $status);

    event(new WebhookHandled(convertedWebhookPayload($stripeId, $status, $type, $previousStatus)));

    expect($response->fresh()->converted_at)->toBeNull();
})->with([
    'incomplete checkout' => ['customer.subscription.created', 'incomplete'],
    'deleted subscription' => ['customer.subscription.deleted', 'canceled'],
    'not a subscription event' => ['invoice.paid', 'active'],
    // An answer stored before this column existed, by a user already paying,
    // must not be stamped with the date of a later renewal or card change.
    'renewal of a live subscription' => ['customer.subscription.updated', 'active'],
    'trial turning paid' => ['customer.subscription.updated', 'active', 'trialing'],
    'canceled subscription' => ['customer.subscription.updated', 'canceled', 'active'],
]);

test('only stamps the answers of the subscriber', function () {
    $subscriber = User::factory()->create();
    $other = FreePlanSurveyResponse::factory()->create();
    $stripeId = subscriptionFor($subscriber, 'active');

    event(new WebhookHandled(convertedWebhookPayload($stripeId, 'active')));

    expect($other->fresh()->converted_at)->toBeNull();
});

test('ignores a subscription unknown locally', function () {
    $response = FreePlanSurveyResponse::factory()->create();

    event(new WebhookHandled(convertedWebhookPayload('sub_unknown', 'active')));

    expect($response->fresh()->converted_at)->toBeNull();
});
