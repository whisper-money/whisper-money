<?php

namespace App\Listeners;

use App\Services\Discord\DiscordWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Laravel\Cashier\Events\WebhookReceived;

class PostStripeEventToDiscord implements ShouldQueue
{
    /**
     * @var array<string, array{title: string, color: int}>
     */
    private const EVENTS = [
        'customer.subscription.created' => ['title' => '🎉 New subscription', 'color' => 0x57F287],
        'customer.subscription.updated' => ['title' => '🔄 Subscription updated', 'color' => 0x5865F2],
        'customer.subscription.deleted' => ['title' => '👋 Subscription cancelled', 'color' => 0xED4245],
        'invoice.payment_succeeded' => ['title' => '💰 Payment succeeded', 'color' => 0x57F287],
        'invoice.payment_failed' => ['title' => '⚠️ Payment failed', 'color' => 0xED4245],
    ];

    public function __construct(private DiscordWebhook $discord) {}

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? null;

        if (! is_string($type) || ! array_key_exists($type, self::EVENTS)) {
            return;
        }

        $this->discord->send('', [$this->buildEmbed($type, $event->payload)]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildEmbed(string $type, array $payload): array
    {
        $object = $payload['data']['object'] ?? [];

        return [
            'title' => self::EVENTS[$type]['title'],
            'color' => self::EVENTS[$type]['color'],
            'fields' => $this->fields($type, $object),
        ];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<int, array<string, mixed>>
     */
    private function fields(string $type, array $object): array
    {
        $fields = [];

        if (str_starts_with($type, 'invoice.')) {
            $amount = match ($type) {
                'invoice.payment_failed' => $object['amount_due'] ?? 0,
                default => $object['amount_paid'] ?? 0,
            };

            $fields[] = [
                'name' => 'Amount',
                'value' => $this->money((int) $amount, (string) ($object['currency'] ?? 'usd')),
                'inline' => true,
            ];
            $fields[] = [
                'name' => 'Customer',
                'value' => (string) ($object['customer_email'] ?? $object['customer'] ?? 'unknown'),
                'inline' => true,
            ];

            return $fields;
        }

        $fields[] = [
            'name' => 'Status',
            'value' => (string) ($object['status'] ?? 'unknown'),
            'inline' => true,
        ];
        $fields[] = [
            'name' => 'Customer',
            'value' => (string) ($object['customer'] ?? 'unknown'),
            'inline' => true,
        ];

        return $fields;
    }

    private function money(int $amount, string $currency): string
    {
        $symbol = match (strtolower($currency)) {
            'eur' => '€',
            'gbp' => '£',
            'usd' => '$',
            'jpy' => '¥',
            'brl' => 'R$',
            default => strtoupper($currency).' ',
        };

        return $symbol.number_format($amount / 100, 2);
    }
}
