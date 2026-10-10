<?php

use App\Enums\SuppressionReason;
use App\Mail\Drip\WelcomeEmail;
use App\Models\SuppressedEmailAddress;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;

/**
 * A long Arabic name Q-encodes into far more than SES's 320 characters, which is
 * what PHP-LARAVEL-77 hit in production.
 */
function overlongArabicName(): string
{
    return str_repeat('عبد الرحمن محمد ', 12);
}

/**
 * `Mail::fake()` never fires `MessageSending`, so the listener is exercised
 * against the real array mailer instead.
 *
 * @return array<int, Address>
 */
function sentRecipients(string $header): array
{
    $messages = Mail::getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1);

    return $messages[0]->getOriginalMessage()->{'get'.$header}();
}

it('drops the name of a recipient whose encoded address is too long for SES', function () {
    $user = User::factory()->create(['name' => overlongArabicName(), 'email' => 'long@example.com']);

    Mail::to($user)->send(new WelcomeEmail($user));

    $to = sentRecipients('To');

    expect($to)->toHaveCount(1)
        ->and($to[0]->getAddress())->toBe('long@example.com')
        ->and($to[0]->getName())->toBe('')
        ->and($user->fresh()->name)->toBe(overlongArabicName());
});

it('keeps the name of a recipient that fits', function () {
    $user = User::factory()->create(['name' => 'عبد الرحمن', 'email' => 'short@example.com']);

    Mail::to($user)->send(new WelcomeEmail($user));

    $to = sentRecipients('To');

    expect($to[0]->getName())->toBe('عبد الرحمن');
});

it('only shortens the overlong recipients in cc and bcc', function () {
    $user = User::factory()->create(['email' => 'fine@example.com']);

    Mail::to($user->email)
        ->cc([new Address('cc@example.com', overlongArabicName()), new Address('ok@example.com', 'Ok')])
        ->bcc(new Address('bcc@example.com', overlongArabicName()))
        ->send(new WelcomeEmail($user));

    $cc = sentRecipients('Cc');
    $bcc = sentRecipients('Bcc');

    expect($cc[0]->getName())->toBe('')
        ->and($cc[1]->getName())->toBe('Ok')
        ->and($bcc[0]->getName())->toBe('')
        ->and($bcc[0]->getAddress())->toBe('bcc@example.com');
});

it('still cancels a send to a suppressed address with an overlong name', function () {
    $user = User::factory()->create(['name' => overlongArabicName(), 'email' => 'gone@example.com']);
    SuppressedEmailAddress::suppress($user->email, SuppressionReason::Bounce);

    Mail::to($user)->send(new WelcomeEmail($user));

    expect(Mail::getSymfonyTransport()->messages())->toBeEmpty();
});
