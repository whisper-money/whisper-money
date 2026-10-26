<?php

use App\Jobs\Drip\SendImportHelpEmailJob;
use App\Mail\Drip\ImportHelpEmail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['subscriptions.enabled' => true]);
});

test('import help email is not sent to non-onboarded users', function () {
    $user = User::factory()->notOnboarded()->create();

    SendImportHelpEmailJob::dispatchSync($user);

    Mail::assertNotQueued(ImportHelpEmail::class);
});

test('import help email is not sent to users with transactions', function () {
    $user = User::factory()->onboarded()->create();
    Transaction::factory()->for($user)->create();

    SendImportHelpEmailJob::dispatchSync($user);

    Mail::assertNotQueued(ImportHelpEmail::class);
});
