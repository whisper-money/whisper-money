<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendFeedbackEmailJob;
use App\Mail\Drip\FeedbackEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['subscriptions.enabled' => true]);
});

test('feedback email is sent to non-subscribed users', function () {
    $user = User::factory()->create();

    SendFeedbackEmailJob::dispatchSync($user);

    Mail::assertSent(FeedbackEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::Feedback->value,
    ]);
});

test('feedback email is not sent to subscribed users', function () {
    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    SendFeedbackEmailJob::dispatchSync($user);

    Mail::assertNotSent(FeedbackEmail::class);
});

test('feedback email is not sent if already received', function () {
    $user = User::factory()->create();

    UserMailLog::factory()->for($user)->feedback()->create();

    SendFeedbackEmailJob::dispatchSync($user);

    Mail::assertNotSent(FeedbackEmail::class);
});
