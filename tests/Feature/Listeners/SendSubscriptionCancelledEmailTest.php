<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Listeners\SendSubscriptionCancelledEmail;
use App\Mail\Drip\CancelledBeforePayingEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Events\WebhookHandled;

beforeEach(function () {
    config(['mail.drip_emails_enabled' => true, 'subscriptions.enabled' => true]);
});

function subscriptionDeletedPayload(array $overrides = [], string $eventId = 'evt_deleted_1'): array
{
    return [
        'id' => $eventId,
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => array_merge([
            'id' => 'sub_deleted',
            'object' => 'subscription',
            'customer' => 'cus_deleted',
            'status' => 'canceled',
            'trial_end' => now()->getTimestamp(),
            'canceled_at' => now()->subDays(3)->getTimestamp(),
            'ended_at' => now()->getTimestamp(),
            'items' => ['data' => []],
        ], $overrides)],
    ];
}

function handleSubscriptionDeleted(array $payload): void
{
    (new SendSubscriptionCancelledEmail)->handle(new WebhookHandled($payload));
}

test('a subscription.deleted webhook queues the email an hour later', function () {
    $this->freezeTime();
    Queue::fake();
    $user = User::factory()->create(['stripe_id' => 'cus_deleted']);

    handleSubscriptionDeleted(subscriptionDeletedPayload());

    Queue::assertPushed(SendSubscriptionCancelledEmailJob::class, fn ($job) => $job->user->is($user)
        && $job->delay->equalTo(now()->addHour()));
});

test('other Stripe events are ignored', function (string $type) {
    Queue::fake();
    User::factory()->create(['stripe_id' => 'cus_deleted']);

    handleSubscriptionDeleted([...subscriptionDeletedPayload(), 'type' => $type]);

    Queue::assertNothingPushed();
})->with(['customer.subscription.updated', 'customer.subscription.trial_will_end', 'invoice.paid']);

test('a redelivery of the event queues nothing more', function () {
    Queue::fake();
    User::factory()->create(['stripe_id' => 'cus_deleted']);

    handleSubscriptionDeleted(subscriptionDeletedPayload(eventId: 'evt_deleted_1'));
    handleSubscriptionDeleted(subscriptionDeletedPayload(eventId: 'evt_deleted_1'));
    handleSubscriptionDeleted(subscriptionDeletedPayload(eventId: 'evt_deleted_2'));

    Queue::assertPushed(SendSubscriptionCancelledEmailJob::class, 1);
});

test('a user who already got the email is not queued again', function () {
    Queue::fake();
    $user = User::factory()->create(['stripe_id' => 'cus_deleted']);
    UserMailLog::factory()->for($user)->create([
        'email_type' => DripEmailType::SubscriptionCancelled,
        'email_identifier' => DripEmailType::SubscriptionCancelled->value,
    ]);

    handleSubscriptionDeleted(subscriptionDeletedPayload());

    Queue::assertNothingPushed();
});

test('nothing is queued while drip emails are switched off', function () {
    Queue::fake();
    User::factory()->create(['stripe_id' => 'cus_deleted']);
    config(['mail.drip_emails_enabled' => false]);

    handleSubscriptionDeleted(subscriptionDeletedPayload());

    Queue::assertNothingPushed();
});

test('nothing is queued for an unknown or missing Stripe customer', function (mixed $customer) {
    Queue::fake();
    User::factory()->create(['stripe_id' => 'cus_other']);

    handleSubscriptionDeleted(subscriptionDeletedPayload(['customer' => $customer]));

    Queue::assertNothingPushed();
})->with(['unknown' => 'cus_deleted', 'missing' => null, 'empty' => '']);

test('a failed dispatch hands the claim back so a redelivery can try again', function () {
    Queue::fake();
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue down'));
    $user = User::factory()->create(['stripe_id' => 'cus_deleted']);

    expect(fn () => handleSubscriptionDeleted(subscriptionDeletedPayload()))->toThrow(RuntimeException::class);
    expect(Cache::has('subscription-cancelled:user:'.$user->id))->toBeFalse();
});

test('a replayed Stripe webhook ends the local subscription and sends the trial variant', function () {
    Mail::fake();
    config(['cashier.webhook.secret' => null]);
    bindPaidInvoices([0]);
    $user = User::factory()->create(['stripe_id' => 'cus_cancelled']);
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_deleted',
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_monthly',
        'trial_ends_at' => now()->addDays(4),
    ]);

    $this->postJson(route('cashier.webhook'), subscriptionDeletedPayload(['customer' => 'cus_cancelled']))
        ->assertOk();

    expect($user->fresh()->subscription('default')->stripe_status)->toBe('canceled');
    Mail::assertQueued(CancelledBeforePayingEmail::class, fn ($mail) => $mail->hasTo($user->email));
});
