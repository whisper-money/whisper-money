<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendImportHelpEmailJob;
use App\Mail\Drip\ImportHelpEmail;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['subscriptions.enabled' => true]);
});

test('import help email is sent to onboarded users without transactions who are not subscribed', function () {
    $user = User::factory()->onboarded()->create();

    SendImportHelpEmailJob::dispatchSync($user);

    Mail::assertSent(ImportHelpEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::ImportHelp->value,
    ]);
});

test('import help email is not sent to non-onboarded users', function () {
    $user = User::factory()->notOnboarded()->create();

    SendImportHelpEmailJob::dispatchSync($user);

    Mail::assertNotSent(ImportHelpEmail::class);
});

test('import help email is not sent to users with transactions', function () {
    $user = User::factory()->onboarded()->create();
    Transaction::factory()->for($user)->create();

    SendImportHelpEmailJob::dispatchSync($user);

    Mail::assertNotSent(ImportHelpEmail::class);
});

test('import help email is not sent to subscribed users', function () {
    $user = User::factory()->onboarded()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    SendImportHelpEmailJob::dispatchSync($user);

    Mail::assertNotSent(ImportHelpEmail::class);
});

test('import help email is not sent if already received', function () {
    $user = User::factory()->onboarded()->create();

    UserMailLog::factory()->for($user)->importHelp()->create();

    SendImportHelpEmailJob::dispatchSync($user);

    Mail::assertNotSent(ImportHelpEmail::class);
});
