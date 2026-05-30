<?php

use App\Listeners\PostStripeEventToDiscord;
use App\Services\Discord\DiscordWebhook;
use Illuminate\Support\Facades\Http;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(function () {
    config()->set('services.discord.webhook_url', 'https://discord.test/webhook');
});

function handleStripeWebhook(array $payload): void
{
    (new PostStripeEventToDiscord(app(DiscordWebhook::class)))
        ->handle(new WebhookReceived($payload));
}

test('posts a message for a created subscription', function () {
    Http::fake();

    handleStripeWebhook([
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['status' => 'active', 'customer' => 'cus_123']],
    ]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'New subscription'));
});

test('posts the formatted amount for a succeeded payment', function () {
    Http::fake();

    handleStripeWebhook([
        'type' => 'invoice.payment_succeeded',
        'data' => ['object' => ['amount_paid' => 1999, 'currency' => 'eur', 'customer_email' => 'a@b.com']],
    ]);

    Http::assertSent(fn ($request) => collect($request['embeds'][0]['fields'])
        ->contains(fn ($field) => $field['value'] === '€19.99'));
});

test('uses amount_due for a failed payment', function () {
    Http::fake();

    handleStripeWebhook([
        'type' => 'invoice.payment_failed',
        'data' => ['object' => ['amount_due' => 500, 'currency' => 'usd']],
    ]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'Payment failed')
        && collect($request['embeds'][0]['fields'])->contains(fn ($field) => $field['value'] === '$5.00'));
});

test('ignores events outside the handled list', function () {
    Http::fake();

    handleStripeWebhook(['type' => 'charge.refunded', 'data' => ['object' => []]]);

    Http::assertNothingSent();
});
