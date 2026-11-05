<?php

use App\Models\User;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * @param  array<string, mixed>  $metadata
 * @return array<string, mixed>
 */
function subscriptionWebhookPayload(string $stripeId, array $metadata, string $type = 'customer.subscription.created'): array
{
    return [
        'type' => $type,
        'data' => [
            'object' => [
                'id' => $stripeId,
                'metadata' => $metadata,
            ],
        ],
    ];
}

test('persists the upsell source from stripe metadata onto the local subscription', function () {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_upsell_123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_upsell_123', ['upsell_source' => 'connections'])));

    expect($subscription->fresh()->upsell_source)->toBe('connections');
});

test('does not overwrite an already-attributed subscription', function () {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_upsell_456',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'upsell_source' => 'accounts',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_upsell_456', ['upsell_source' => 'connections'], 'customer.subscription.updated')));

    expect($subscription->fresh()->upsell_source)->toBe('accounts');
});

test('leaves the upsell source empty without a known value in the metadata', function (array $metadata) {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_upsell_789',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_upsell_789', $metadata)));

    expect($subscription->fresh()->upsell_source)->toBeNull();
})->with([
    'unknown upsell source value' => [['upsell_source' => 'bogus']],
    'no upsell metadata' => [[]],
]);

test('persists the preselected plan from stripe metadata onto the local subscription', function () {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_preselected_123',
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_preselected_123', [
        'upsell_source' => 'onboarding_bank',
        'preselected_plan' => 'monthly',
    ])));

    expect($subscription->fresh())
        ->upsell_source->toBe('onboarding_bank')
        ->preselected_plan->toBe('monthly');
});

test('persists the preselected plan without an upsell source', function () {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_preselected_456',
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_preselected_456', ['preselected_plan' => 'yearly'])));

    expect($subscription->fresh())
        ->upsell_source->toBeNull()
        ->preselected_plan->toBe('yearly');
});

test('does not overwrite an already-recorded preselected plan', function () {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_preselected_789',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'preselected_plan' => 'yearly',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_preselected_789', ['preselected_plan' => 'monthly'], 'customer.subscription.updated')));

    expect($subscription->fresh()->preselected_plan)->toBe('yearly');
});

test('leaves the preselected plan empty when the metadata names no plan', function (array $metadata) {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_preselected_000',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_preselected_000', $metadata)));

    expect($subscription->fresh()->preselected_plan)->toBeNull();
})->with([
    'unknown plan' => [['preselected_plan' => 'weekly']],
    'no preselected plan metadata' => [[]],
]);
