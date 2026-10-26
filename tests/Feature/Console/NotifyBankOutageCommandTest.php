<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Enums\DripEmailType;
use App\Jobs\SyncBankingConnectionJob;
use App\Mail\BankOutageEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    Mail::fake();
});

/**
 * A user with one Enable Banking connection to "QA Outage Bank" (ES), in the
 * default active state unless the attributes say otherwise.
 *
 * @param  array<string, mixed>  $connection
 */
function outageUser(string $email, array $connection = []): User
{
    $user = User::factory()->create(['email' => $email]);

    BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'QA Outage Bank',
        'aspsp_country' => 'ES',
        ...$connection,
    ]);

    return $user;
}

test('notifies every user connected to the affected bank', function () {
    $first = outageUser('first@example.com');
    $second = outageUser('second@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('2 user(s) to notify about the QA Outage Bank (ES) outage.')
        ->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 2);

    foreach ([$first, $second] as $user) {
        Mail::assertQueued(BankOutageEmail::class, fn (BankOutageEmail $mail) => $mail->hasTo($user->email)
            && $mail->bankName === 'QA Outage Bank');
    }
});

test('notifies a connection already erroring, as long as it is still retried', function () {
    outageUser('erroring@example.com', [
        'status' => BankingConnectionStatus::Error,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES - 1,
    ]);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 1);
});

test('sends a single email to a user with several connections to the same bank', function () {
    $user = outageUser('multi@example.com');
    BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'QA Outage Bank',
        'aspsp_country' => 'ES',
    ]);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('1 user(s) to notify')
        ->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 1);
});

test('finds no connection to a bank it should not touch', function (Closure $seed, string $output) {
    $seed();

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain($output)
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
})->with([
    'users of other banks' => [
        fn () => outageUser('other-bank@example.com', ['aspsp_name' => 'CaixaBank']),
        'No Enable Banking connection to QA Outage Bank.',
    ],
    'connections of other providers that share the bank name' => [
        fn () => outageUser('wise@example.com', ['provider' => BankingProvider::Wise]),
        'No Enable Banking connection',
    ],
    'soft-deleted connections' => [
        fn () => outageUser('gone@example.com')->bankingConnections()->first()->delete(),
        'No Enable Banking connection',
    ],
]);

test('the country option disambiguates banks sharing a name', function () {
    $spanish = outageUser('es@example.com');
    outageUser('de@example.com', ['aspsp_country' => 'DE']);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--country' => 'es', '--force' => true])
        ->expectsOutputToContain('1 user(s) to notify about the QA Outage Bank (ES) outage.')
        ->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 1);
    Mail::assertQueued(BankOutageEmail::class, fn (BankOutageEmail $mail) => $mail->hasTo($spanish->email));
});

test('refuses to guess when the bank is affected in several countries', function () {
    outageUser('es@example.com');
    outageUser('de@example.com', ['aspsp_country' => 'DE']);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('QA Outage Bank is affected in DE, ES')
        ->expectsOutputToContain('re-run with --country=XX')
        ->assertFailed();

    Mail::assertNothingOutgoing();
});

test('emails the bank name as stored, not as typed', function () {
    outageUser('cased@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'qa outage bank', '--force' => true])->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, fn (BankOutageEmail $mail) => $mail->bankName === 'QA Outage Bank');
});

test('skips connections the outage does not explain', function (Closure $seed) {
    $seed();

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('none is waiting on the bank')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
})->with([
    ...collect(['expired', 'revoked', 'pending', 'awaitingMapping'])->mapWithKeys(fn (string $state) => [
        $state => fn () => BankingConnection::factory()
            ->for(User::factory()->create(['email' => "{$state}@example.com"]))
            ->{$state}()
            ->create(['aspsp_name' => 'QA Outage Bank', 'aspsp_country' => 'ES']),
    ])->all(),
    // Nothing syncs a connection whose owner is gone, so it is not waiting on
    // the bank either - which is what `banking:health` was counting it as.
    'soft-deleted users' => fn () => outageUser('deleted@example.com')->delete(),
    'stranded past the retry cap' => fn () => outageUser('stranded@example.com', [
        'status' => BankingConnectionStatus::Error,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES,
    ]),
    'consent already lapsed' => fn () => outageUser('lapsed@example.com', ['valid_until' => now()->subDay()]),
]);

test('warns about the connections left out of a send', function () {
    outageUser('affected@example.com');
    outageUser('lapsed@example.com', ['valid_until' => now()->subDay()]);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('Skipping 1 other connection(s) to QA Outage Bank (ES)')
        ->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 1);
});

test('records a mail log per notified user', function () {
    $user = outageUser('logged@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])->assertSuccessful();

    assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::BankOutage->value,
        'email_identifier' => 'qa-outage-bank-es',
    ]);
});

test('suggests the real bank name when the argument matches nothing', function () {
    outageUser('typo@example.com', ['aspsp_name' => 'Banco QA Outage Bank']);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('Did you mean: Banco QA Outage Bank?')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});
