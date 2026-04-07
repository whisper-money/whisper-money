<?php

use App\Mail\BankingConnectionAuthFailedEmail;
use App\Mail\BankTransactionsSyncedEmail;
use App\Mail\BrokenBankLogosReportEmail;
use App\Mail\Drip\FeedbackEmail;
use App\Mail\Drip\ImportHelpEmail;
use App\Mail\Drip\OnboardingReminderEmail;
use App\Mail\Drip\PromoCodeEmail;
use App\Mail\Drip\SubscriptionCancelledEmail;
use App\Mail\Drip\WelcomeEmail;
use App\Mail\UpdateEmail;
use App\Mail\WaitlistOvertaken;
use App\Mail\WaitlistReferralNotification;
use App\Mail\WaitlistWelcome;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserLead;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Mail\Mailables\Address;
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

test('drip mailables use the drip sender', function (string $mailableClass) {
    $user = User::factory()->create();

    $mailable = match ($mailableClass) {
        WelcomeEmail::class => new WelcomeEmail($user),
        FeedbackEmail::class => new FeedbackEmail($user),
        ImportHelpEmail::class => new ImportHelpEmail($user),
        OnboardingReminderEmail::class => new OnboardingReminderEmail($user),
        PromoCodeEmail::class => new PromoCodeEmail($user),
        SubscriptionCancelledEmail::class => new SubscriptionCancelledEmail($user),
    };

    expect($mailable->envelope()->from)->toEqual(new Address('hi@whisper.money', 'Álvaro and Víctor'));
})->with([
    WelcomeEmail::class,
    FeedbackEmail::class,
    ImportHelpEmail::class,
    OnboardingReminderEmail::class,
    PromoCodeEmail::class,
    SubscriptionCancelledEmail::class,
]);

test('default sender is used for active non-drip mailables', function (string $mailableClass) {
    $user = User::factory()->create();

    $mailable = match ($mailableClass) {
        UpdateEmail::class => new UpdateEmail($user, 'test-update'),
        BankTransactionsSyncedEmail::class => new BankTransactionsSyncedEmail($user, 3, ['Test Bank' => 3]),
        BankingConnectionAuthFailedEmail::class => new BankingConnectionAuthFailedEmail($user, BankingConnection::factory()->for($user)->create(['aspsp_name' => 'Test Bank'])),
        BrokenBankLogosReportEmail::class => new BrokenBankLogosReportEmail([['id' => 'bank-1', 'name' => 'Test Bank', 'previous_logo' => 'https://example.com/logo.png']]),
        WaitlistWelcome::class => new WaitlistWelcome(UserLead::factory()->create()),
        WaitlistReferralNotification::class => new WaitlistReferralNotification(UserLead::factory()->create()),
        WaitlistOvertaken::class => new WaitlistOvertaken(UserLead::factory()->create()),
    };

    sendWithArrayMailer($mailable);

    $from = lastSentMailMessage()->getOriginalMessage()->getFrom()[0];

    expect($from->getAddress())->toBe('no-reply@whisper.money')
        ->and($from->getName())->toBe('Whisper Money');
})->with([
    UpdateEmail::class,
    BankTransactionsSyncedEmail::class,
    BankingConnectionAuthFailedEmail::class,
    BrokenBankLogosReportEmail::class,
    WaitlistWelcome::class,
    WaitlistReferralNotification::class,
    WaitlistOvertaken::class,
]);

test('transaction sync email envelope explicitly uses the default sender', function () {
    $user = User::factory()->create();

    $mailable = new BankTransactionsSyncedEmail($user, 3, ['Test Bank' => 3]);

    expect($mailable->envelope()->from)->toEqual(new Address('no-reply@whisper.money', 'Whisper Money'));
});

test('verification notification uses the default sender', function () {
    $user = User::factory()->unverified()->create();

    $user->notify(new VerifyEmailNotification);

    $from = lastSentMailMessage()->getOriginalMessage()->getFrom()[0];

    expect($from->getAddress())->toBe('no-reply@whisper.money')
        ->and($from->getName())->toBe('Whisper Money');
});
