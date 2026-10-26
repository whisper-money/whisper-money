<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendOnboardingReminderEmailJob;
use App\Mail\Drip\OnboardingReminderEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

test('onboarding reminder email is not sent to onboarded users', function () {
    $user = User::factory()->onboarded()->create();

    SendOnboardingReminderEmailJob::dispatchSync($user);

    Mail::assertNotQueued(OnboardingReminderEmail::class);

    $this->assertDatabaseMissing('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::OnboardingReminder->value,
    ]);
});
