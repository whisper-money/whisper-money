<?php

use App\Enums\DripEmailType;
use App\Mail\BankConnectFailedEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    Mail::fake();
});

/**
 * An authorization the bank sent back as an error: a pending connection that
 * handleAuthorizationError() soft-deleted, never having produced a session.
 *
 * @param  array<string, mixed>  $attributes
 */
function failedAttempt(User $user, array $attributes = []): BankingConnection
{
    $connection = BankingConnection::factory()->for($user)->pending()->create([
        'aspsp_name' => 'QA Broken Bank',
        'aspsp_country' => 'ES',
        ...$attributes,
    ]);

    $connection->delete();

    return $connection;
}

/**
 * A second victim, so the bank clears the "more than one user" evidence bar.
 *
 * @param  array<string, mixed>  $attributes
 */
function otherVictim(array $attributes = []): User
{
    return userWithFailedAttempts('other-victim@example.com', 1, $attributes);
}

function userWithFailedAttempts(string $email, int $attempts = 1, array $attributes = []): User
{
    $user = User::factory()->create(['email' => $email]);

    for ($i = 0; $i < $attempts; $i++) {
        failedAttempt($user, $attributes);
    }

    return $user;
}

test('notifies everyone whose authorization the bank rejected', function () {
    $persistent = userWithFailedAttempts('tried-five-times@example.com', 5);
    $once = userWithFailedAttempts('tried-once@example.com');

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])
        ->expectsOutputToContain('2 user(s) tried and failed to connect QA Broken Bank (ES).')
        ->assertSuccessful();

    Mail::assertQueued(BankConnectFailedEmail::class, 2);

    foreach ([$persistent, $once] as $user) {
        Mail::assertQueued(BankConnectFailedEmail::class, fn (BankConnectFailedEmail $mail) => $mail->hasTo($user->email)
            && $mail->bankName === 'QA Broken Bank');
    }
});

test('shows how many times each user tried and when they last did', function () {
    userWithFailedAttempts('tried-five-times@example.com', 5);
    otherVictim();

    // Asserted on the whole output rather than with expectsOutputToContain():
    // that helper consumes one substring per written line, so two columns of the
    // same table row can never both be matched.
    Artisan::call('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--dry-run' => true]);

    expect(Artisan::output())
        ->toContain('Failed attempts')
        ->toContain('Last attempt')
        ->toContain('tried-five-times@example.com')
        ->toContain('| 5')
        ->toContain(now()->toDateString());
});

test('leaves alone the users who simply never came back from the bank', function () {
    $user = User::factory()->create(['email' => 'abandoned@example.com']);
    BankingConnection::factory()->for($user)->pending()->create([
        'aspsp_name' => 'QA Broken Bank',
        'aspsp_country' => 'ES',
    ]);

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])
        ->expectsOutputToContain('came back as a bank error')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('refuses to run for a bank that does connect', function () {
    userWithFailedAttempts('unlucky@example.com', 2);

    $working = User::factory()->create(['email' => 'connected@example.com']);
    BankingConnection::factory()->for($working)->create([
        'aspsp_name' => 'QA Broken Bank',
        'aspsp_country' => 'ES',
    ]);

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])
        ->expectsOutputToContain('has completed 1 authorization(s), so it does connect')
        ->expectsOutputToContain('never once connected')
        ->assertFailed();

    Mail::assertNothingOutgoing();
});

test('refuses to guess when attempts to the bank span several countries', function () {
    userWithFailedAttempts('es@example.com');
    userWithFailedAttempts('de@example.com', 1, ['aspsp_country' => 'DE']);

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])
        ->expectsOutputToContain('QA Broken Bank is affected in DE, ES')
        ->expectsOutputToContain('re-run with --country=XX')
        ->assertFailed();

    Mail::assertNothingOutgoing();
});

test('the country option narrows the attempts', function () {
    $spanish = userWithFailedAttempts('es@example.com');
    otherVictim();
    userWithFailedAttempts('de@example.com', 1, ['aspsp_country' => 'DE']);

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--country' => 'es', '--force' => true])
        ->expectsOutputToContain('2 user(s) tried and failed to connect QA Broken Bank (ES).')
        ->assertSuccessful();

    Mail::assertQueued(BankConnectFailedEmail::class, 2);
    Mail::assertQueued(BankConnectFailedEmail::class, fn (BankConnectFailedEmail $mail) => $mail->hasTo($spanish->email));
});

test('emails the bank name as stored, not as typed', function () {
    userWithFailedAttempts('cased@example.com');
    otherVictim();

    artisan('banking:notify-connect-failure', ['aspsp' => 'qa broken bank', '--force' => true])->assertSuccessful();

    Mail::assertQueued(BankConnectFailedEmail::class, fn (BankConnectFailedEmail $mail) => $mail->bankName === 'QA Broken Bank');
});

test('suggests a similar bank name when the argument matches nothing', function () {
    userWithFailedAttempts('typo@example.com', 1, ['aspsp_name' => 'Banco QA Broken Bank']);

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])
        ->expectsOutputToContain('Did you mean: Banco QA Broken Bank?')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('skips soft-deleted users', function () {
    $deleted = userWithFailedAttempts('deleted@example.com');
    $deleted->delete();
    otherVictim();
    userWithFailedAttempts('live@example.com');

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])
        ->expectsOutputToContain('2 user(s) tried and failed to connect QA Broken Bank (ES).')
        ->assertSuccessful();

    Mail::assertQueued(BankConnectFailedEmail::class, 2);
    Mail::assertNotQueued(BankConnectFailedEmail::class, fn (BankConnectFailedEmail $mail) => $mail->hasTo($deleted->email));
});

test('records a mail log and does not notify the same user twice', function () {
    $user = userWithFailedAttempts('once@example.com');
    otherVictim();

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])->assertSuccessful();

    assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::BankConnectFailed->value,
        'email_identifier' => 'qa-broken-bank-es',
    ]);
    assertDatabaseCount('user_mail_logs', 2);

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])
        ->expectsOutputToContain('Nobody to notify')
        ->assertSuccessful();

    Mail::assertQueued(BankConnectFailedEmail::class, 2);
});

test('resend notifies the users who already got the notice', function () {
    userWithFailedAttempts('again@example.com');
    otherVictim();

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])->assertSuccessful();
    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true, '--resend' => true])
        ->expectsConfirmation('Tell 2 user(s) that QA Broken Bank (ES) cannot be connected?', 'yes')
        ->assertSuccessful();

    Mail::assertQueued(BankConnectFailedEmail::class, 4);
    assertDatabaseCount('user_mail_logs', 2);
});

test('a dry run reports the recipients and sends nothing', function () {
    userWithFailedAttempts('dry@example.com');
    otherVictim();

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--dry-run' => true])
        ->expectsOutputToContain('dry@example.com')
        ->expectsOutputToContain('[dry-run] No emails sent.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
    assertDatabaseCount('user_mail_logs', 0);
});

test('sends nothing when the confirmation is declined', function () {
    userWithFailedAttempts('confirm@example.com');
    otherVictim();

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank'])
        ->expectsConfirmation('Tell 2 user(s) that QA Broken Bank (ES) cannot be connected?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
    assertDatabaseCount('user_mail_logs', 0);
});

test('refuses to run when only one person ever hit the bank', function () {
    userWithFailedAttempts('cancelled-twice@example.com', 2);

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Broken Bank', '--force' => true])
        ->expectsOutputToContain('Only 1 user(s) have ever hit a failed authorization')
        ->expectsOutputToContain('too little to claim the bank is broken')
        ->assertFailed();

    Mail::assertNothingOutgoing();
});

test('a deliberate disconnect is not a failed authorization', function () {
    $user = User::factory()->create(['email' => 'disconnected@example.com']);
    $connection = BankingConnection::factory()->for($user)->revoked()->create([
        'aspsp_name' => 'QA Revoked Bank',
        'aspsp_country' => 'ES',
        'session_id' => null,
    ]);
    $connection->delete();

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Revoked Bank', '--force' => true])
        ->expectsOutputToContain('came back as a bank error')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('a failed reconnect of a working connection is not a failed authorization', function () {
    $user = User::factory()->create(['email' => 'reconnect@example.com']);
    BankingConnection::factory()->for($user)->error()->create([
        'aspsp_name' => 'QA Reconnect Bank',
        'aspsp_country' => 'ES',
        'session_id' => null,
    ]);

    artisan('banking:notify-connect-failure', ['aspsp' => 'QA Reconnect Bank', '--force' => true])
        ->expectsOutputToContain('came back as a bank error')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('since narrows the recipients to recent attempts', function () {
    $recent = userWithFailedAttempts('recent@example.com');
    otherVictim();

    $old = userWithFailedAttempts('gave-up-long-ago@example.com');
    $old->bankingConnections()->onlyTrashed()->update(['created_at' => now()->subYear()]);

    artisan('banking:notify-connect-failure', [
        'aspsp' => 'QA Broken Bank',
        '--since' => now()->subMonth()->toDateString(),
        '--force' => true,
    ])
        ->expectsOutputToContain('2 user(s) tried and failed to connect QA Broken Bank (ES).')
        ->assertSuccessful();

    Mail::assertQueued(BankConnectFailedEmail::class, 2);
    Mail::assertNotQueued(BankConnectFailedEmail::class, fn (BankConnectFailedEmail $mail) => $mail->hasTo($old->email));
    Mail::assertQueued(BankConnectFailedEmail::class, fn (BankConnectFailedEmail $mail) => $mail->hasTo($recent->email));
});
