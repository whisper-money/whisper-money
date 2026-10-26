<?php

use App\Mail\BankConnectFailedEmail;
use App\Mail\BankingConnectionAuthFailedEmail;
use App\Mail\BankOutageEmail;
use App\Mail\BankTransactionsSyncedEmail;
use App\Mail\BrokenBankLogosReportEmail;
use App\Mail\Drip\PromoCodeEmail;
use App\Mail\EnableBankingConnectionsCancelledEmail;
use App\Mail\UpdateEmail;
use App\Mail\UserEmailsReportEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'no-reply@whisper.money',
        'mail.from.name' => 'Whisper Money',
        'mail.drip_from.address' => 'hi@whisper.money',
        'mail.drip_from.name' => 'Álvaro and Víctor',
    ]);

    $viewPath = resource_path('views/mail/updates');

    if (! File::exists($viewPath)) {
        File::makeDirectory($viewPath, 0755, true);
    }

    File::put(resource_path('views/mail/updates/test-update.blade.php'), <<<'BLADE'
<x-mail::message>
# Test Update

Hello {{ $user->name }},

Test update.
</x-mail::message>
BLADE);
});

afterEach(function () {
    $testViewPath = resource_path('views/mail/updates/test-update.blade.php');

    if (File::exists($testViewPath)) {
        File::delete($testViewPath);
    }
});

function lastSentMailMessage(): object
{
    /** @var Mailer $mailer */
    $mailer = Mail::mailer('array');
    /** @var ArrayTransport $transport */
    $transport = $mailer->getSymfonyTransport();

    return $transport->messages()->last();
}

function sendWithArrayMailer($mailable): void
{
    /** @var Mailer $mailer */
    $mailer = Mail::mailer('array');
    /** @var ArrayTransport $transport */
    $transport = $mailer->getSymfonyTransport();
    $transport->flush();

    $mailer->to('recipient@example.com')->sendNow($mailable);
}

test('drip mailables hand their template UTM parameters for PostHog attribution', function () {
    $user = User::factory()->create();

    expect((new PromoCodeEmail($user))->content()->with['emailUtm'])
        ->toBe([
            'utm_source' => 'drip',
            'utm_medium' => 'email',
            'utm_campaign' => 'promo-code',
        ]);
});

test('default sender is used for active non-drip mailables', function (string $mailableClass) {
    $user = User::factory()->create();

    $mailable = match ($mailableClass) {
        UpdateEmail::class => new UpdateEmail($user, 'test-update'),
        BankTransactionsSyncedEmail::class => new BankTransactionsSyncedEmail($user, 3, ['Test Bank' => 3]),
        BankingConnectionAuthFailedEmail::class => new BankingConnectionAuthFailedEmail($user, BankingConnection::factory()->for($user)->create(['aspsp_name' => 'Test Bank'])),
        BankOutageEmail::class => new BankOutageEmail($user, 'Test Bank'),
        BankConnectFailedEmail::class => new BankConnectFailedEmail($user, 'Test Bank'),
        EnableBankingConnectionsCancelledEmail::class => new EnableBankingConnectionsCancelledEmail($user, 2),
        BrokenBankLogosReportEmail::class => new BrokenBankLogosReportEmail([['id' => 'bank-1', 'name' => 'Test Bank', 'previous_logo' => 'https://example.com/logo.png']]),
        UserEmailsReportEmail::class => new UserEmailsReportEmail("email\ntest@example.com\n", 1, 'user-emails-2026-01-01.csv'),
    };

    sendWithArrayMailer($mailable);

    $from = lastSentMailMessage()->getOriginalMessage()->getFrom()[0];

    expect($from->getAddress())->toBe('no-reply@whisper.money')
        ->and($from->getName())->toBe('Whisper Money');
})->with([
    UpdateEmail::class,
    BankTransactionsSyncedEmail::class,
    BankingConnectionAuthFailedEmail::class,
    BankOutageEmail::class,
    BankConnectFailedEmail::class,
    EnableBankingConnectionsCancelledEmail::class,
    BrokenBankLogosReportEmail::class,
    UserEmailsReportEmail::class,
]);

test('update email translates its subject and leaves untranslated ones verbatim', function () {
    $user = User::factory()->create(['locale' => 'es']);

    // The subject doubles as a translation key, resolved in the user's locale.
    (new UpdateEmail($user, 'first-update-jan-2026', 'Ask ChatGPT how much you spent this month'))
        ->locale($user->preferredLocale())
        ->assertHasSubject('Pregúntale a ChatGPT cuánto has gastado este mes');

    // A subject with no translation still goes out exactly as it was typed.
    (new UpdateEmail($user, 'first-update-jan-2026', 'Update from Whisper Money'))
        ->locale($user->preferredLocale())
        ->assertHasSubject('Update from Whisper Money');
});

test('bank outage email does not send the user to their connections', function () {
    $user = User::factory()->create(['name' => 'Test User']);

    // The outage is on the bank side, so there is nothing for the user to fix.
    (new BankOutageEmail($user, 'Openbank'))->assertDontSeeInHtml(route('settings.connections.index'));
});
