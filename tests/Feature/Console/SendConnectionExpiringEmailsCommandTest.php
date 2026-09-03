<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\DripEmailType;
use App\Jobs\Drip\SendConnectionExpiringEmailJob;
use App\Mail\Drip\ConnectionExpiringEmail;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

// Freeze time so a connection placed a fixed number of days from expiry cannot
// drift across the edge of the warning window mid-test.
beforeEach(fn () => $this->freezeTime());

/**
 * An active EnableBanking connection whose consent runs out in $inDays days.
 *
 * @param  array<string, mixed>  $attributes
 */
function expiringConnection(int $inDays = 3, array $attributes = []): BankingConnection
{
    return BankingConnection::factory()->create([
        'valid_until' => now()->addDays($inDays),
        ...$attributes,
    ]);
}

it('queues the warning for a connection whose consent runs out inside the window', function () {
    Bus::fake();

    $connection = expiringConnection();

    $this->artisan('email:connection-expiring')->assertSuccessful();

    Bus::assertDispatched(
        SendConnectionExpiringEmailJob::class,
        fn (SendConnectionExpiringEmailJob $job): bool => $job->bankingConnection->is($connection)
            && $job->user->is($connection->user),
    );
});

it('leaves alone a consent with more than a week to run', function () {
    Bus::fake();

    expiringConnection(inDays: 8);

    $this->artisan('email:connection-expiring')->assertSuccessful();

    Bus::assertNotDispatched(SendConnectionExpiringEmailJob::class);
});

it('leaves the already-expired ones to the sync job, which emails them itself', function () {
    Bus::fake();

    BankingConnection::factory()->expired()->create();
    expiringConnection(inDays: -1);

    $this->artisan('email:connection-expiring')->assertSuccessful();

    Bus::assertNotDispatched(SendConnectionExpiringEmailJob::class);
});

it('ignores connections that are not active', function (BankingConnectionStatus $status) {
    Bus::fake();

    expiringConnection(attributes: ['status' => $status]);

    $this->artisan('email:connection-expiring')->assertSuccessful();

    Bus::assertNotDispatched(SendConnectionExpiringEmailJob::class);
})->with([
    BankingConnectionStatus::Pending,
    BankingConnectionStatus::AwaitingMapping,
    BankingConnectionStatus::Revoked,
    BankingConnectionStatus::Error,
]);

it('ignores providers that have no consent to renew', function () {
    Bus::fake();

    BankingConnection::factory()->indexaCapital()->create([
        'valid_until' => now()->addDays(3),
    ]);

    $this->artisan('email:connection-expiring')->assertSuccessful();

    Bus::assertNotDispatched(SendConnectionExpiringEmailJob::class);
});

it('leaves the shared demo account out of it', function () {
    Bus::fake();

    expiringConnection(attributes: [
        'user_id' => User::factory()->create(['email' => config('app.demo.email')])->id,
    ]);

    $this->artisan('email:connection-expiring')->assertSuccessful();

    Bus::assertNotDispatched(SendConnectionExpiringEmailJob::class);
});

it('does nothing when drip emails are disabled', function () {
    config(['mail.drip_emails_enabled' => false]);
    Bus::fake();

    expiringConnection();

    $this->artisan('email:connection-expiring')->assertSuccessful();

    Bus::assertNotDispatched(SendConnectionExpiringEmailJob::class);
});

it('warns once per consent window however many days the command runs', function () {
    Mail::fake();

    $connection = expiringConnection(inDays: 6);

    $this->artisan('email:connection-expiring')->assertSuccessful();
    $this->travel(1)->days();
    $this->artisan('email:connection-expiring')->assertSuccessful();

    Mail::assertQueuedCount(1);
    expect(UserMailLog::where('user_id', $connection->user_id)->count())->toBe(1);
});

it('sends the email and records a mail log keyed on the consent window', function () {
    Mail::fake();

    $connection = expiringConnection();

    (new SendConnectionExpiringEmailJob($connection->user, $connection))->handle();

    Mail::assertQueued(ConnectionExpiringEmail::class);

    $log = UserMailLog::where('user_id', $connection->user_id)->sole();
    expect($log->email_type)->toBe(DripEmailType::ConnectionExpiring);
    expect($log->email_identifier)->toBe($connection->id.':'.$connection->valid_until->toDateString());
});

it('warns again once the next consent window is running out', function () {
    Mail::fake();

    $connection = expiringConnection();
    (new SendConnectionExpiringEmailJob($connection->user, $connection))->handle();

    // The user renewed, and months later that window is running out too.
    $connection->update(['valid_until' => now()->addDays(3)->addMonths(3)]);
    $this->travel(3)->months();

    (new SendConnectionExpiringEmailJob($connection->user, $connection))->handle();

    Mail::assertQueuedCount(2);
    expect(UserMailLog::where('user_id', $connection->user_id)->count())->toBe(2);
});

it('does not send once the user has already renewed the consent', function () {
    Mail::fake();

    $connection = expiringConnection();

    // Renewed between the command queueing the job and the queue running it.
    $connection->update(['valid_until' => now()->addDays(90)]);

    (new SendConnectionExpiringEmailJob($connection->user, $connection))->handle();

    Mail::assertNothingQueued();
    expect(UserMailLog::where('user_id', $connection->user_id)->count())->toBe(0);
});

it('does not send once the user has disconnected the bank', function () {
    Mail::fake();

    $connection = expiringConnection();
    $connection->delete();

    (new SendConnectionExpiringEmailJob($connection->user, $connection))->handle();

    Mail::assertNothingQueued();
});

it('points its button at the reconnect route so the user can renew from the email', function () {
    $connection = expiringConnection();

    $html = (new ConnectionExpiringEmail($connection->user, $connection))->render();

    expect($html)->toContain(route('open-banking.reconnect', $connection).'?utm_source=drip');
});

it('renders the email in the user locale', function () {
    $connection = expiringConnection(attributes: ['aspsp_name' => 'Bankinter']);
    $connection->user->update(['name' => 'Ada']);

    app()->setLocale('es');

    try {
        $html = (new ConnectionExpiringEmail($connection->user->fresh(), $connection))->render();
    } finally {
        app()->setLocale('en');
    }

    expect($html)->toContain('Renueva tu conexión con Bankinter')
        ->toContain('Renovar conexión');
});
