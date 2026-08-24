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

test('it never warns a subscribed user', function () {
    config(['subscriptions.enabled' => true]);

    $subscribed = User::factory()->create();
    Transaction::factory()->for($subscribed)->create();
    $subscribed->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    artisan('encryption:notify-removal', ['--force' => true])->assertSuccessful();

    Queue::assertNotPushed(SendUpdateEmailJob::class);
});

test('it always reports the total count of non-deleted encrypted users, including subscribed ones', function () {
    config(['subscriptions.enabled' => true]);

    $subscribed = User::factory()->create();
    Transaction::factory()->for($subscribed)->create();
    $subscribed->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $unsubscribed = User::factory()->create();
    Transaction::factory()->for($unsubscribed)->create();

    $deleted = User::factory()->create();
    Transaction::factory()->for($deleted)->create();
    $deleted->delete();

    $clean = User::factory()->create();
    Transaction::factory()->for($clean)->plaintext()->create();

    // The count spans everyone still holding encrypted data (subscribed included); the
    // soft-deleted and plaintext users are excluded.
    artisan('encryption:notify-removal', ['--force' => true])
        ->expectsOutputToContain('2 non-deleted user(s) still have encrypted data.')
        ->assertSuccessful();

    // Sending is unchanged: the subscribed user is counted but never emailed.
    Queue::assertPushed(SendUpdateEmailJob::class, 1);
    Queue::assertPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job) => $job->user->is($unsubscribed));
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
