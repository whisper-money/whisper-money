<?php

use App\Enums\SuppressionReason;
use App\Jobs\Drip\SendWelcomeEmailJob;
use App\Mail\Drip\WelcomeEmail;
use App\Models\SuppressedEmailAddress;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

const TOPIC_ARN = 'arn:aws:sns:eu-west-1:123456789012:whisper-ses-feedback';

beforeEach(function () {
    config(['services.ses.topic_arn' => TOPIC_ARN]);
});

/**
 * SNS posts the raw JSON body as `text/plain`, so the test has to as well: a
 * controller reading `$request->all()` would pass against `postJson` and get
 * nothing in production.
 */
function postToSnsWebhook(array $payload, ?string $url = null): TestResponse
{
    return test()->call(
        'POST',
        $url ?? URL::signedRoute('ses.feedback'),
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        json_encode($payload),
    );
}

function snsNotification(array $message): array
{
    return [
        'Type' => 'Notification',
        'TopicArn' => TOPIC_ARN,
        'Message' => json_encode($message),
    ];
}

function bounceMessage(string $bounceType, string $email): array
{
    return [
        'notificationType' => 'Bounce',
        'bounce' => [
            'bounceType' => $bounceType,
            'bouncedRecipients' => [['emailAddress' => $email]],
        ],
    ];
}

it('suppresses an address that hard bounces', function () {
    postToSnsWebhook(snsNotification(bounceMessage('Permanent', 'gone@example.com')))
        ->assertOk();

    expect(SuppressedEmailAddress::isSuppressed('gone@example.com'))->toBeTrue();
    expect(SuppressedEmailAddress::first()->reason)->toBe(SuppressionReason::Bounce);
});

it('suppresses every address in a complaint', function () {
    postToSnsWebhook(snsNotification([
        'notificationType' => 'Complaint',
        'complaint' => [
            'complainedRecipients' => [
                ['emailAddress' => 'angry@example.com'],
                ['emailAddress' => 'alsoangry@example.com'],
            ],
        ],
    ]))->assertOk();

    expect(SuppressedEmailAddress::isSuppressed('angry@example.com'))->toBeTrue();
    expect(SuppressedEmailAddress::isSuppressed('alsoangry@example.com'))->toBeTrue();
    expect(SuppressedEmailAddress::first()->reason)->toBe(SuppressionReason::Complaint);
});

it('reads the newer eventType field as well as notificationType', function () {
    $message = bounceMessage('Permanent', 'gone@example.com');
    unset($message['notificationType']);
    $message['eventType'] = 'Bounce';

    postToSnsWebhook(snsNotification($message))->assertOk();

    expect(SuppressedEmailAddress::isSuppressed('gone@example.com'))->toBeTrue();
});

it('stores the address lowercased so it matches whatever casing we hold', function () {
    postToSnsWebhook(snsNotification(bounceMessage('Permanent', ' Gone@Example.COM ')))
        ->assertOk();

    expect(SuppressedEmailAddress::first()->email)->toBe('gone@example.com');
    expect(SuppressedEmailAddress::isSuppressed('GONE@example.com'))->toBeTrue();
});

it('ignores transient and undetermined bounces', function (string $bounceType) {
    postToSnsWebhook(snsNotification(bounceMessage($bounceType, 'busy@example.com')))
        ->assertOk();

    expect(SuppressedEmailAddress::count())->toBe(0);
})->with(['Transient', 'Undetermined']);

it('ignores deliveries and anything else SNS sends', function () {
    postToSnsWebhook(snsNotification([
        'notificationType' => 'Delivery',
        'delivery' => ['recipients' => ['fine@example.com']],
    ]))->assertOk();

    postToSnsWebhook(['Type' => 'UnsubscribeConfirmation', 'TopicArn' => TOPIC_ARN])
        ->assertOk();

    expect(SuppressedEmailAddress::count())->toBe(0);
});

it('rejects an unsigned request', function () {
    postToSnsWebhook(
        snsNotification(bounceMessage('Permanent', 'gone@example.com')),
        url('/api/ses/feedback'),
    )->assertForbidden();

    expect(SuppressedEmailAddress::count())->toBe(0);
});

it('rejects a notification published by another topic', function () {
    $payload = snsNotification(bounceMessage('Permanent', 'gone@example.com'));
    $payload['TopicArn'] = 'arn:aws:sns:eu-west-1:999999999999:someone-elses-topic';

    postToSnsWebhook($payload)->assertForbidden();

    expect(SuppressedEmailAddress::count())->toBe(0);
});

it('rejects everything when no topic is configured', function () {
    config(['services.ses.topic_arn' => null]);

    $payload = snsNotification(bounceMessage('Permanent', 'gone@example.com'));
    $payload['TopicArn'] = '';

    postToSnsWebhook($payload)->assertForbidden();
});

it('confirms a subscription by visiting the URL SNS sent', function () {
    Http::fake();

    postToSnsWebhook([
        'Type' => 'SubscriptionConfirmation',
        'TopicArn' => TOPIC_ARN,
        'SubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc',
    ])->assertOk();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://sns.eu-west-1.amazonaws.com/'));
});

it('refuses to fetch a confirmation URL that is not an AWS host', function (string $subscribeUrl) {
    Http::fake();

    postToSnsWebhook([
        'Type' => 'SubscriptionConfirmation',
        'TopicArn' => TOPIC_ARN,
        'SubscribeURL' => $subscribeUrl,
    ])->assertOk();

    Http::assertNothingSent();
})->with([
    'https://evil.example.com/?Action=ConfirmSubscription',
    'https://sns.eu-west-1.amazonaws.com.evil.example.com/',
    'http://sns.eu-west-1.amazonaws.com/',
    'http://169.254.169.254/latest/meta-data/',
]);

it('prints a signed webhook URL that the endpoint accepts', function () {
    $this->artisan('ses:feedback-url')->assertSuccessful();

    postToSnsWebhook(snsNotification(bounceMessage('Permanent', 'gone@example.com')))
        ->assertOk();
});

it('stops a user with a suppressed address receiving emails', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);

    expect($user->canReceiveEmails())->toBeTrue();

    SuppressedEmailAddress::suppress('gone@example.com', SuppressionReason::Bounce);

    expect($user->canReceiveEmails())->toBeFalse();
    expect($user->routeNotificationForMail())->toBeNull();
});

it('leaves a drip email pending rather than logging it as sent', function () {
    Mail::fake();

    $user = User::factory()->create(['email' => 'gone@example.com']);
    SuppressedEmailAddress::suppress($user->email, SuppressionReason::Bounce);

    (new SendWelcomeEmailJob($user))->handle();

    Mail::assertNothingSent();
    expect(UserMailLog::count())->toBe(0);
});

/**
 * `Mail::fake()` never fires `MessageSending`, so the listener is exercised
 * against the real array mailer instead.
 */
it('cancels a send to a suppressed address', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    SuppressedEmailAddress::suppress($user->email, SuppressionReason::Bounce);

    Mail::to($user->email)->send(new WelcomeEmail($user));

    expect(Mail::getSymfonyTransport()->messages())->toBeEmpty();
});

it('still sends to an address that is not suppressed', function () {
    $user = User::factory()->create(['email' => 'fine@example.com']);
    SuppressedEmailAddress::suppress('someone.else@example.com', SuppressionReason::Bounce);

    Mail::to($user->email)->send(new WelcomeEmail($user));

    expect(Mail::getSymfonyTransport()->messages())->toHaveCount(1);
});

it('drops only the suppressed recipient when a message has several', function () {
    $user = User::factory()->create(['email' => 'fine@example.com']);
    SuppressedEmailAddress::suppress('gone@example.com', SuppressionReason::Bounce);

    Mail::to(['fine@example.com', 'gone@example.com'])->send(new WelcomeEmail($user));

    $messages = Mail::getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1);
    expect($messages[0]->getOriginalMessage()->getTo())->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('fine@example.com');
});
