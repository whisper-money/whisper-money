<?php

use App\Jobs\SendUpdateEmailJob;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

beforeEach(fn () => Queue::fake());

test('it queues a warning email for users with encrypted transactions or accounts', function () {
    $encryptedTransaction = User::factory()->create();
    Transaction::factory()->for($encryptedTransaction)->create(); // description_iv set by default

    $encryptedAccount = User::factory()->create();
    Account::factory()->for($encryptedAccount)->create(['name_iv' => 'abcdef0123456789']);

    $clean = User::factory()->create();
    Transaction::factory()->for($clean)->plaintext()->create();

    artisan('encryption:notify-removal', ['--force' => true])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, 2);
    Queue::assertPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job) => $job->user->is($encryptedTransaction));
    Queue::assertPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job) => $job->user->is($encryptedAccount));
    Queue::assertNotPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job) => $job->user->is($clean));
});

test('it queues on the emails queue', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->create();

    artisan('encryption:notify-removal', ['--force' => true])->assertSuccessful();

    Queue::assertPushedOn('emails', SendUpdateEmailJob::class);
});

test('dry run sends nothing', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->create();

    artisan('encryption:notify-removal', ['--dry-run' => true])->assertSuccessful();

    Queue::assertNotPushed(SendUpdateEmailJob::class);
});
