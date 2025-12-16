<?php

use App\Jobs\Drip\SendFeedbackEmailJob;
use App\Jobs\Drip\SendImportHelpEmailJob;
use App\Jobs\Drip\SendOnboardingReminderEmailJob;
use App\Jobs\Drip\SendPromoCodeEmailJob;
use App\Jobs\Drip\SendWelcomeEmailJob;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('all drip email jobs are dispatched when user registers', function () {
    $user = User::factory()->create();

    event(new Registered($user));

    Queue::assertPushed(SendWelcomeEmailJob::class, function ($job) use ($user) {
        return $job->user->id === $user->id;
    });

    Queue::assertPushed(SendOnboardingReminderEmailJob::class, function ($job) use ($user) {
        return $job->user->id === $user->id;
    });

    Queue::assertPushed(SendPromoCodeEmailJob::class, function ($job) use ($user) {
        return $job->user->id === $user->id;
    });

    Queue::assertPushed(SendImportHelpEmailJob::class, function ($job) use ($user) {
        return $job->user->id === $user->id;
    });

    Queue::assertPushed(SendFeedbackEmailJob::class, function ($job) use ($user) {
        return $job->user->id === $user->id;
    });
});

test('welcome email job is dispatched with delay', function () {
    $user = User::factory()->create();

    event(new Registered($user));

    Queue::assertPushed(SendWelcomeEmailJob::class, function ($job) {
        return $job->delay !== null;
    });
});

test('onboarding reminder job is dispatched with delay', function () {
    $user = User::factory()->create();

    event(new Registered($user));

    Queue::assertPushed(SendOnboardingReminderEmailJob::class, function ($job) {
        return $job->delay !== null;
    });
});

test('feedback email job is dispatched with delay', function () {
    $user = User::factory()->create();

    event(new Registered($user));

    Queue::assertPushed(SendFeedbackEmailJob::class, function ($job) {
        return $job->delay !== null;
    });
});

test('all jobs are dispatched to the emails queue', function () {
    $user = User::factory()->create();

    event(new Registered($user));

    Queue::assertPushedOn('emails', SendWelcomeEmailJob::class);
    Queue::assertPushedOn('emails', SendOnboardingReminderEmailJob::class);
    Queue::assertPushedOn('emails', SendPromoCodeEmailJob::class);
    Queue::assertPushedOn('emails', SendImportHelpEmailJob::class);
    Queue::assertPushedOn('emails', SendFeedbackEmailJob::class);
});
