<?php

use App\Listeners\PostStripeEventToDiscord;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stripe\StripeCustomerResolver;
use Illuminate\Support\Facades\Http;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(function () {
    config()->set('services.discord.webhook_url', 'https://discord.test/webhook');
});

function handleStripeWebhook(array $payload): void
{
    $resolver = new class extends StripeCustomerResolver
    {
        public function label(?string $customerId): string
        {
            return $customerId === null ? 'unknown' : "Jane Doe ({$customerId})";
        }
    };

    (new PostStripeEventToDiscord(app(DiscordWebhook::class), $resolver))
        ->handle(new WebhookReceived($payload));
}

/**
 * @return array<int, array<string, mixed>>
 */
function sentFields(): array
{
    $fields = [];

    Http::assertSent(function ($request) use (&$fields) {
        $fields = $request['embeds'][0]['fields'] ?? [];

        return true;
    });

    return $fields;
}

test('posts a message for a created subscription with rich fields', function () {
    Http::fake();

    handleStripeWebhook([
        'id' => 'evt_1',
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_123',
            'status' => 'active',
            'customer' => 'cus_123',
            'items' => ['data' => [['price' => [
                'unit_amount' => 1999,
                'currency' => 'eur',
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ]]]],
        ]],
    ]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'New subscription'));

    $fields = collect(sentFields());
    expect($fields->firstWhere('name', 'Customer')['value'])->toBe('Jane Doe (cus_123)');
    expect($fields->firstWhere('name', 'Plan')['value'])->toBe('€19.99 / month');
    expect($fields->firstWhere('name', 'Subscription')['value'])->toBe('sub_123');
});

test('posts the formatted amount for a succeeded payment', function () {
    Http::fake();

    handleStripeWebhook([
        'id' => 'evt_2',
        'type' => 'invoice.payment_succeeded',
        'data' => ['object' => ['amount_paid' => 1999, 'currency' => 'eur', 'customer_email' => 'a@b.com']],
    ]);

    Http::assertSent(fn ($request) => collect($request['embeds'][0]['fields'])
        ->contains(fn ($field) => $field['value'] === '€19.99'));
});

test('skips a zero-amount payment so only the subscription message is posted', function () {
    Http::fake();

    handleStripeWebhook([
        'id' => 'evt_zero',
        'type' => 'invoice.payment_succeeded',
        'data' => ['object' => ['amount_paid' => 0, 'currency' => 'eur', 'customer_email' => 'a@b.com', 'subscription' => 'sub_123']],
    ]);

    Http::assertNothingSent();
});

test('uses amount_due for a failed payment', function () {
    Http::fake();

    handleStripeWebhook([
        'id' => 'evt_3',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => ['amount_due' => 500, 'currency' => 'usd']],
    ]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'Payment failed')
        && collect($request['embeds'][0]['fields'])->contains(fn ($field) => $field['value'] === '$5.00'));
});

test('ignores events outside the handled list', function () {
    Http::fake();

    handleStripeWebhook(['id' => 'evt_4', 'type' => 'charge.refunded', 'data' => ['object' => []]]);

    Http::assertNothingSent();
});

test('skips duplicate deliveries of the same event id', function () {
    Http::fake();

    $payload = [
        'id' => 'evt_dup',
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_1']],
    ];

    handleStripeWebhook($payload);
    handleStripeWebhook($payload);

    Http::assertSentCount(1);
});

test('labels a scheduled cancellation distinctly from a deletion', function () {
    Http::fake();

    handleStripeWebhook([
        'id' => 'evt_5',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_1', 'cancel_at_period_end' => true],
            'previous_attributes' => ['cancel_at_period_end' => false],
        ],
    ]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'Cancellation scheduled'));
});

test('reports the status change on a meaningful update', function () {
    Http::fake();

    handleStripeWebhook([
        'id' => 'evt_6',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_1'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'status changed')
        && collect($request['embeds'][0]['fields'])
            ->contains(fn ($field) => $field['name'] === 'Changed' && str_contains($field['value'], 'trialing → active')));
});

test('reports the old and new plan on a plan change', function () {
    Http::fake();

    $price = fn (int $amount) => [
        'items' => ['data' => [['price' => [
            'unit_amount' => $amount,
            'currency' => 'eur',
            'recurring' => ['interval' => 'month', 'interval_count' => 1],
        ]]]],
    ];

    handleStripeWebhook([
        'id' => 'evt_plan_change',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_1'] + $price(399),
            'previous_attributes' => $price(999),
        ],
    ]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'Plan changed')
        && collect($request['embeds'][0]['fields'])
            ->contains(fn ($field) => $field['name'] === 'Changed' && str_contains($field['value'], '€9.99 / month → €3.99 / month')));
});

test('ignores trivial subscription updates', function () {
    Http::fake();

    handleStripeWebhook([
        'id' => 'evt_7',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_1'],
            'previous_attributes' => ['latest_invoice' => 'in_123'],
        ],
    ]);

    Http::assertNothingSent();
});

test('posts a cancellation for a deleted subscription with the reason', function () {
    Http::fake();

    handleStripeWebhook([
        'id' => 'evt_8',
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => [
            'id' => 'sub_1',
            'status' => 'canceled',
            'customer' => 'cus_1',
            'cancellation_details' => ['reason' => 'cancellation_requested'],
        ]],
    ]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'Subscription cancelled')
        && collect($request['embeds'][0]['fields'])
            ->contains(fn ($field) => $field['name'] === 'Cancellation reason' && $field['value'] === 'cancellation requested'));
});
