<?php

use App\Enums\BankingProvider;
use App\Mail\BankOutageEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Mail::fake();
});

function outageUser(string $email, array $connection = []): User
{
    $user = User::factory()->create(['email' => $email]);

    BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'Openbank',
        'aspsp_country' => 'ES',
        ...$connection,
    ]);

    return $user;
}

test('notifies every user connected to the affected bank', function () {
    $first = outageUser('first@example.com');
    $second = outageUser('second@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'Openbank', '--force' => true])
        ->expectsOutputToContain('2 user(s) to notify about the Openbank outage.')
        ->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 2);

    foreach ([$first, $second] as $user) {
        Mail::assertQueued(BankOutageEmail::class, fn (BankOutageEmail $mail) => $mail->hasTo($user->email)
            && $mail->bankName === 'Openbank');
    }
});

test('sends a single email to a user with several connections to the same bank', function () {
    $user = outageUser('multi@example.com');
    BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'Openbank',
        'aspsp_country' => 'ES',
    ]);

    artisan('banking:notify-outage', ['aspsp' => 'Openbank', '--force' => true])
        ->expectsOutputToContain('1 user(s) to notify')
        ->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 1);
});

test('leaves users of other banks alone', function () {
    outageUser('other-bank@example.com', ['aspsp_name' => 'CaixaBank']);

    artisan('banking:notify-outage', ['aspsp' => 'Openbank', '--force' => true])
        ->expectsOutputToContain('No users to notify: no live Enable Banking connection to Openbank.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('the country option disambiguates banks sharing a name', function () {
    $spanish = outageUser('es@example.com');
    outageUser('de@example.com', ['aspsp_country' => 'DE']);

    artisan('banking:notify-outage', ['aspsp' => 'Openbank', '--country' => 'ES', '--force' => true])
        ->expectsOutputToContain('1 user(s) to notify about the Openbank (ES) outage.')
        ->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, 1);
    Mail::assertQueued(BankOutageEmail::class, fn (BankOutageEmail $mail) => $mail->hasTo($spanish->email));
});

test('emails the bank name as stored, not as typed', function () {
    outageUser('cased@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'openbank', '--force' => true])->assertSuccessful();

    Mail::assertQueued(BankOutageEmail::class, fn (BankOutageEmail $mail) => $mail->bankName === 'Openbank');
});

test('ignores connections of other providers that share the bank name', function () {
    outageUser('wise@example.com', ['provider' => BankingProvider::Wise]);

    artisan('banking:notify-outage', ['aspsp' => 'Openbank', '--force' => true])
        ->expectsOutputToContain('No users to notify')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('ignores soft-deleted connections', function () {
    $user = outageUser('gone@example.com');
    $user->bankingConnections()->first()->delete();

    artisan('banking:notify-outage', ['aspsp' => 'Openbank', '--force' => true])
        ->expectsOutputToContain('No users to notify')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('skips users who cannot receive emails', function () {
    $optedOut = outageUser('deleted@example.com');
    $optedOut->delete();

    artisan('banking:notify-outage', ['aspsp' => 'Openbank', '--force' => true])
        ->expectsOutputToContain('No users to notify')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('a dry run reports the recipients and sends nothing', function () {
    outageUser('dry@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'Openbank', '--dry-run' => true])
        ->expectsOutputToContain('dry@example.com')
        ->expectsOutputToContain('[dry-run] No emails sent.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});

test('sends nothing when the confirmation is declined', function () {
    outageUser('confirm@example.com');

    artisan('banking:notify-outage', ['aspsp' => 'Openbank'])
        ->expectsConfirmation('Send the Openbank outage notice to 1 user(s)?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
});
