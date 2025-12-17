<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendOnboardingReminderEmailJob;
use App\Mail\Drip\OnboardingReminderEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

test('onboarding reminder email is sent to non-onboarded users', function () {
    $user = User::factory()->notOnboarded()->create();

    SendOnboardingReminderEmailJob::dispatchSync($user);

    Mail::assertSent(OnboardingReminderEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::OnboardingReminder->value,
    ]);
});

test('onboarding reminder email is not sent to onboarded users', function () {
    $user = User::factory()->onboarded()->create();

    SendOnboardingReminderEmailJob::dispatchSync($user);

    Mail::assertNotSent(OnboardingReminderEmail::class);

    $this->assertDatabaseMissing('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::OnboardingReminder->value,
    ]);
});

test('onboarding reminder email is not sent if already received', function () {
    $user = User::factory()->notOnboarded()->create();

    UserMailLog::factory()->for($user)->onboardingReminder()->create();

    SendOnboardingReminderEmailJob::dispatchSync($user);

    Mail::assertNotSent(OnboardingReminderEmail::class);
});
