<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Mail\Drip\SubscriptionCancelledEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

test('subscription cancelled email is sent and logged', function () {
    $user = User::factory()->create();

    SendSubscriptionCancelledEmailJob::dispatchSync($user);

    Mail::assertQueued(SubscriptionCancelledEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::SubscriptionCancelled->value,
    ]);
});

test('subscription cancelled email is not sent if already received', function () {
    $user = User::factory()->create();

    UserMailLog::factory()->for($user)->subscriptionCancelled()->create();

    SendSubscriptionCancelledEmailJob::dispatchSync($user);

    Mail::assertNotQueued(SubscriptionCancelledEmail::class);
});
