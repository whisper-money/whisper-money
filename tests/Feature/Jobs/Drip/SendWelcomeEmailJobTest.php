<?php

use App\Jobs\Drip\SendWelcomeEmailJob;
use App\Mail\Drip\WelcomeEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

test('welcome email is not sent to deleted users', function () {
    $user = User::factory()->create();
    $user->delete();

    SendWelcomeEmailJob::dispatchSync($user);

    Mail::assertNotQueued(WelcomeEmail::class);
    expect(UserMailLog::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
