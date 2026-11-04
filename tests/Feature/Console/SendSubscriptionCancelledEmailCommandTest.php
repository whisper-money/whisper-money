<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Queue::fake();
});

test('command dispatches job for valid user email', function () {
    $user = User::factory()->create();

    artisan('email:subscription-cancelled', ['emails' => $user->email])
        ->assertSuccessful();

    Queue::assertPushed(SendSubscriptionCancelledEmailJob::class, function ($job) use ($user) {
        return $job->user->id === $user->id;
    });
    Queue::assertPushedOn('emails', SendSubscriptionCancelledEmailJob::class);
});

test('command dispatches jobs for multiple user emails', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    artisan('email:subscription-cancelled', ['emails' => "{$user1->email},{$user2->email}"])
        ->assertSuccessful();

    Queue::assertPushed(SendSubscriptionCancelledEmailJob::class, function ($job) use ($user1) {
        return $job->user->id === $user1->id;
    });

    Queue::assertPushed(SendSubscriptionCancelledEmailJob::class, function ($job) use ($user2) {
        return $job->user->id === $user2->id;
    });
});

test('command skips an address that is no user', function (string $email) {
    artisan('email:subscription-cancelled', ['emails' => $email])
        ->assertSuccessful();

    Queue::assertNothingPushed();
})->with([
    'non-existent user' => 'nonexistent@example.com',
    'invalid email format' => 'not-an-email',
]);

test('command handles mixed valid and invalid emails', function () {
    $user = User::factory()->create();

    artisan('email:subscription-cancelled', ['emails' => "invalid,{$user->email},nonexistent@example.com"])
        ->assertSuccessful();

    Queue::assertPushed(SendSubscriptionCancelledEmailJob::class, fn ($job) => $job->user->id === $user->id);
    Queue::assertCount(1);
});

test('command reports why it skips a user instead of claiming a send', function () {
    config(['subscriptions.enabled' => true]);
    $subscribed = User::factory()->create();
    $subscribed->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_still_active',
        'stripe_status' => 'active',
        'stripe_price' => 'price_monthly',
    ]);
    $alreadySent = User::factory()->create();
    UserMailLog::factory()->for($alreadySent)->create([
        'email_type' => DripEmailType::SubscriptionCancelled,
        'email_identifier' => DripEmailType::SubscriptionCancelled->value,
    ]);

    artisan('email:subscription-cancelled', ['emails' => "{$subscribed->email},{$alreadySent->email}"])
        ->expectsTable(['Email', 'Status'], [
            [$subscribed->email, 'Still subscribed'],
            [$alreadySent->email, 'Already sent'],
        ])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
