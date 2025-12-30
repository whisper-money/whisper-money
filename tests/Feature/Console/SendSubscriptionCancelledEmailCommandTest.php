<?php

use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Models\User;
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

test('command handles non-existent user gracefully', function () {
    artisan('email:subscription-cancelled', ['emails' => 'nonexistent@example.com'])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command handles invalid email format gracefully', function () {
    artisan('email:subscription-cancelled', ['emails' => 'not-an-email'])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command handles mixed valid and invalid emails', function () {
    $user = User::factory()->create();

    artisan('email:subscription-cancelled', ['emails' => "invalid,{$user->email},nonexistent@example.com"])
        ->assertSuccessful();

    Queue::assertPushed(SendSubscriptionCancelledEmailJob::class, fn ($job) => $job->user->id === $user->id);
    Queue::assertCount(1);
});

test('job is dispatched to emails queue', function () {
    $user = User::factory()->create();

    artisan('email:subscription-cancelled', ['emails' => $user->email])
        ->assertSuccessful();

    Queue::assertPushedOn('emails', SendSubscriptionCancelledEmailJob::class);
});
