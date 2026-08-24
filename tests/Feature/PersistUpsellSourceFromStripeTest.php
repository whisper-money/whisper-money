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

test('ignores an unknown upsell source value', function () {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_upsell_789',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_upsell_789', ['upsell_source' => 'bogus'])));

    expect($subscription->fresh()->upsell_source)->toBeNull();
});

test('does nothing for subscriptions without upsell metadata', function () {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_upsell_000',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    event(new WebhookHandled(subscriptionWebhookPayload('sub_upsell_000', [])));

    expect($subscription->fresh()->upsell_source)->toBeNull();
});
