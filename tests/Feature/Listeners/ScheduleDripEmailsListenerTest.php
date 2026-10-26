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

    Queue::assertNotPushed(SendPromoCodeEmailJob::class);

    Queue::assertPushed(SendImportHelpEmailJob::class, function ($job) use ($user) {
        return $job->user->id === $user->id;
    });

    Queue::assertPushed(SendFeedbackEmailJob::class, function ($job) use ($user) {
        return $job->user->id === $user->id;
    });
});

test('drip email job is dispatched with delay', function (string $job) {
    $user = User::factory()->create();

    event(new Registered($user));

    Queue::assertPushed($job, function ($pushed) {
        return $pushed->delay !== null;
    });
})->with([
    'welcome' => SendWelcomeEmailJob::class,
    'onboarding reminder' => SendOnboardingReminderEmailJob::class,
    'feedback' => SendFeedbackEmailJob::class,
]);

test('all jobs are dispatched to the emails queue', function () {
    $user = User::factory()->create();

    event(new Registered($user));

    Queue::assertPushedOn('emails', SendWelcomeEmailJob::class);
    Queue::assertPushedOn('emails', SendOnboardingReminderEmailJob::class);
    Queue::assertPushedOn('emails', SendImportHelpEmailJob::class);
    Queue::assertPushedOn('emails', SendFeedbackEmailJob::class);
});

test('no drip emails are dispatched when disabled', function () {
    config(['mail.drip_emails_enabled' => false]);

    $user = User::factory()->create();

    event(new Registered($user));

    Queue::assertNotPushed(SendWelcomeEmailJob::class);
    Queue::assertNotPushed(SendOnboardingReminderEmailJob::class);
    Queue::assertNotPushed(SendPromoCodeEmailJob::class);
    Queue::assertNotPushed(SendImportHelpEmailJob::class);
    Queue::assertNotPushed(SendFeedbackEmailJob::class);
});
