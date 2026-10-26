<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\BankNotifyCommandScenario;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseCount;

beforeEach(function () {
    Mail::fake();

    $this->notices = sys_get_temp_dir().'/notices-'.Str::random(8);
    File::ensureDirectoryExists($this->notices);
});

afterEach(function () {
    File::deleteDirectory($this->notices);
});

dataset('bank notify commands', [
    'banking:notify-outage' => fn (): BankNotifyCommandScenario => BankNotifyCommandScenario::outage(),
    'banking:notify-connect-failure' => fn (): BankNotifyCommandScenario => BankNotifyCommandScenario::connectFailure(),
    'banking:notify-users' => fn (): BankNotifyCommandScenario => BankNotifyCommandScenario::notice($this->notices),
]);

test('a dry run reports the recipients and sends nothing', function (BankNotifyCommandScenario $scenario) {
    artisan($scenario->command, [...$scenario->arguments, '--dry-run' => true])
        ->expectsOutputToContain($scenario->recipientEmail)
        ->expectsOutputToContain('[dry-run] No emails sent.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
    assertDatabaseCount('user_mail_logs', 0);
})->with('bank notify commands');

test('sends nothing when the confirmation is declined', function (BankNotifyCommandScenario $scenario) {
    artisan($scenario->command, $scenario->arguments)
        ->expectsConfirmation($scenario->confirmation, 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
    assertDatabaseCount('user_mail_logs', 0);
})->with('bank notify commands');

test('does not notify the same user twice', function (BankNotifyCommandScenario $scenario) {
    artisan($scenario->command, [...$scenario->arguments, '--force' => true])->assertSuccessful();

    artisan($scenario->command, [...$scenario->arguments, '--force' => true])
        ->expectsOutputToContain($scenario->alreadyNotified)
        ->assertSuccessful();

    Mail::assertQueued($scenario->mail, $scenario->recipients);
    assertDatabaseCount('user_mail_logs', $scenario->recipients);
})->with('bank notify commands');

test('resend asks for confirmation even with force, and sends again once given', function (BankNotifyCommandScenario $scenario) {
    artisan($scenario->command, [...$scenario->arguments, '--force' => true])->assertSuccessful();

    // --resend always prompts, even with --force: it is the one flag pair that
    // puts a second copy of the same email in a real inbox.
    artisan($scenario->command, [...$scenario->arguments, '--force' => true, '--resend' => true])
        ->expectsConfirmation($scenario->confirmation, 'yes')
        ->assertSuccessful();

    Mail::assertQueued($scenario->mail, $scenario->recipients * 2);
    assertDatabaseCount('user_mail_logs', $scenario->recipients);
})->with('bank notify commands');
