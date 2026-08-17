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
use function Pest\Laravel\assertDatabaseCount;
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

test('leaves users of other banks alone', function () {
    outageUser('other-bank@example.com', ['aspsp_name' => 'CaixaBank']);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('No Enable Banking connection to QA Outage Bank.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

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
        ->expectsOutputToContain('has affected connections in DE, ES')
        ->expectsOutputToContain('re-run with --country=XX')
        ->assertFailed();

    Mail::assertNothingOutgoing();
});

test('emails the bank name as stored, not as typed', function () {
    outageUser('cased@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'qa outage bank', '--force' => true])->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, fn (BankOutageEmail $mail) => $mail->bankName === 'QA Outage Bank');
});

test('ignores connections of other providers that share the bank name', function () {
    outageUser('wise@example.com', ['provider' => BankingProvider::Wise]);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('No Enable Banking connection')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('ignores soft-deleted connections', function () {
    $user = outageUser('gone@example.com');
    $user->bankingConnections()->first()->delete();

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('No Enable Banking connection')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('skips soft-deleted users', function () {
    outageUser('deleted@example.com')->delete();

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('skips connections the outage does not explain', function (string $state) {
    $user = User::factory()->create(['email' => "{$state}@example.com"]);
    BankingConnection::factory()->for($user)->{$state}()->create([
        'aspsp_name' => 'QA Outage Bank',
        'aspsp_country' => 'ES',
    ]);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('none is waiting on the bank')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
})->with(['expired', 'revoked', 'pending', 'awaitingMapping']);

test('skips a connection stranded past the retry cap', function () {
    outageUser('stranded@example.com', [
        'status' => BankingConnectionStatus::Error,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES,
    ]);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('none is waiting on the bank')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('skips a connection whose consent has already lapsed', function () {
    outageUser('lapsed@example.com', ['valid_until' => now()->subDay()]);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('none is waiting on the bank')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

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

test('does not notify the same user twice for the same outage', function () {
    outageUser('once@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])->assertSuccessful();

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('Nobody to notify')
        ->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 1);
    assertDatabaseCount('user_mail_logs', 1);
});

test('resend notifies the users who already got the notice', function () {
    outageUser('again@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])->assertSuccessful();
    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true, '--resend' => true])->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 2);
    assertDatabaseCount('user_mail_logs', 1);
});

test('a dry run reports the recipients and sends nothing', function () {
    outageUser('dry@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--dry-run' => true])
        ->expectsOutputToContain('dry@example.com')
        ->expectsOutputToContain('[dry-run] No emails sent.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
    assertDatabaseCount('user_mail_logs', 0);
});

test('sends nothing when the confirmation is declined', function () {
    outageUser('confirm@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank'])
        ->expectsConfirmation('Send the QA Outage Bank (ES) outage notice to 1 user(s)?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
    assertDatabaseCount('user_mail_logs', 0);
});

test('suggests the real bank name when the argument matches nothing', function () {
    outageUser('typo@example.com', ['aspsp_name' => 'Banco QA Outage Bank']);

    artisan('banking:notify-outage', ['aspsp' => 'QA Outage Bank', '--force' => true])
        ->expectsOutputToContain('Did you mean: Banco QA Outage Bank?')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});
