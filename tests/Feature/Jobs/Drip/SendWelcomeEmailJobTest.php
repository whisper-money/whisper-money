<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendWelcomeEmailJob;
use App\Mail\Drip\WelcomeEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

test('welcome email is sent and logged', function () {
    $user = User::factory()->create();

    SendWelcomeEmailJob::dispatchSync($user);

    Mail::assertQueued(WelcomeEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::Welcome->value,
    ]);
});

test('welcome email is not sent if already received', function () {
    $user = User::factory()->create();

    UserMailLog::factory()->for($user)->welcome()->create();

    SendWelcomeEmailJob::dispatchSync($user);

    Mail::assertNotQueued(WelcomeEmail::class);
});
