<?php

namespace App\Listeners;

use App\Services\Discord\DiscordWebhook;
use App\Services\Stripe\StripeCustomerResolver;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Events\WebhookReceived;

class PostStripeEventToDiscord implements ShouldQueue
{
    private const DEDUPE_TTL_HOURS = 24;

    private const COLOR_GREEN = 0x57F287;

    private const COLOR_BLUE = 0x5865F2;

    private const COLOR_RED = 0xED4245;

    private const COLOR_ORANGE = 0xFEE75C;

    public function __construct(
        private DiscordWebhook $discord,
        private StripeCustomerResolver $customers,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? null;

        if (! is_string($type)) {
            return;
        }

        if ($this->alreadyProcessed($event->payload)) {
            return;
        }

        $embed = match (true) {
            str_starts_with($type, 'invoice.') => $this->invoiceEmbed($type, $event->payload),
            str_starts_with($type, 'customer.subscription.') => $this->subscriptionEmbed($type, $event->payload),
            default => null,
        };

        if ($embed === null) {
            return;
        }

        $this->discord->send('', [$embed]);
    }

    /**
     * Guard against duplicate deliveries: Stripe retries webhooks and the
     * queued job may itself be retried. The Stripe event id is unique per
     * event, so the first delivery wins and later ones are skipped.
     *
     * @param  array<string, mixed>  $payload
     */
    private function alreadyProcessed(array $payload): bool
    {
        $id = $payload['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return false;
        }

        return ! Cache::add('discord:stripe-event:'.$id, true, now()->addHours(self::DEDUPE_TTL_HOURS));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function subscriptionEmbed(string $type, array $payload): ?array
    {
        $object = $payload['data']['object'] ?? [];
        $previous = $payload['data']['previous_attributes'] ?? [];

        $meta = match ($type) {
            'customer.subscription.created' => ['title' => '🎉 New subscription', 'color' => self::COLOR_GREEN],
            'customer.subscription.deleted' => ['title' => '👋 Subscription cancelled', 'color' => self::COLOR_RED],
            'customer.subscription.updated' => $this->updatedMeta($object, $previous),
            default => null,
        };

        if ($meta === null) {
            return null;
        }

        $fields = [
            $this->field('Customer', $this->customers->label($this->stringOrNull($object['customer'] ?? null)), true),
            $this->field('Status', (string) ($object['status'] ?? 'unknown'), true),
        ];

        if ($plan = $this->planLabel($object)) {
            $fields[] = $this->field('Plan', $plan, true);
        }

        if ($changes = $this->changeSummary($object, $previous)) {
            $fields[] = $this->field('Changed', $changes, false);
        }

        if ($reason = $this->cancellationReason($object)) {
            $fields[] = $this->field('Cancellation reason', $reason, false);
        }

        if (($object['cancel_at_period_end'] ?? false) === true && $end = $this->timestamp($object['current_period_end'] ?? null)) {
            $fields[] = $this->field('Ends at', $end, true);
        }

        if (($object['status'] ?? null) === 'trialing' && $trialEnd = $this->timestamp($object['trial_end'] ?? null)) {
            $fields[] = $this->field('Trial ends', $trialEnd, true);
        }

        $fields[] = $this->field('Subscription', (string) ($object['id'] ?? 'unknown'), false);

        return [
            'title' => $meta['title'],
            'color' => $meta['color'],
            'fields' => $fields,
        ];
    }

    /**
     * Decide whether a subscription.updated event is worth posting, and how to
     * label it. Stripe fires this event for many trivial changes, so we only
     * surface meaningful ones: a status change, a plan change, or a
     * cancellation being scheduled or reverted.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $previous
     * @return array{title: string, color: int}|null
     */
    private function updatedMeta(array $object, array $previous): ?array
    {
        if (array_key_exists('cancel_at_period_end', $previous)) {
            return ($object['cancel_at_period_end'] ?? false) === true
                ? ['title' => '🗓️ Cancellation scheduled', 'color' => self::COLOR_ORANGE]
                : ['title' => '♻️ Cancellation reverted', 'color' => self::COLOR_BLUE];
        }

        if (array_key_exists('status', $previous)) {
            return ['title' => '🔄 Subscription status changed', 'color' => self::COLOR_BLUE];
        }

        if (array_key_exists('items', $previous) || array_key_exists('plan', $previous)) {
            return ['title' => '🔄 Plan changed', 'color' => self::COLOR_BLUE];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function invoiceEmbed(string $type, array $payload): ?array
    {
        $object = $payload['data']['object'] ?? [];

        $meta = match ($type) {
            'invoice.payment_failed' => ['title' => '⚠️ Payment failed', 'color' => self::COLOR_RED, 'amount' => $object['amount_due'] ?? 0],
            default => ['title' => '💰 Payment succeeded', 'color' => self::COLOR_GREEN, 'amount' => $object['amount_paid'] ?? 0],
        };

        if ((int) $meta['amount'] === 0) {
            return null;
        }

        $email = $this->stringOrNull($object['customer_email'] ?? null);

        $fields = [
            $this->field('Amount', Money::format((int) $meta['amount'], (string) ($object['currency'] ?? 'usd')), true),
            $this->field('Customer', $email ?? $this->customers->label($this->stringOrNull($object['customer'] ?? null)), true),
        ];

        if ($number = $this->stringOrNull($object['number'] ?? null)) {
            $fields[] = $this->field('Invoice', $number, true);
        }

        if ($subscription = $this->stringOrNull($object['subscription'] ?? null)) {
            $fields[] = $this->field('Subscription', $subscription, false);
        }

        return [
            'title' => $meta['title'],
            'color' => $meta['color'],
            'fields' => $fields,
        ];
    }

    /**
     * Build a human-readable summary of what changed, using Stripe's
     * previous_attributes diff.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $previous
     */
    private function changeSummary(array $object, array $previous): ?string
    {
        $lines = [];

        if (array_key_exists('status', $previous)) {
            $lines[] = sprintf('Status: %s → %s', $previous['status'] ?? 'unknown', $object['status'] ?? 'unknown');
        }

        if (array_key_exists('items', $previous) || array_key_exists('plan', $previous)) {
            $new = $this->planLabel($object) ?? 'updated';
            $old = $this->planLabel($previous);
            $lines[] = ($old !== null && $old !== $new)
                ? sprintf('Plan: %s → %s', $old, $new)
                : 'Plan: '.$new;
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * Build a "€3.99 / month" label from either a subscription object, or a
     * previous_attributes diff. Handles both the modern `items.data[].price`
     * shape and the legacy top-level `plan` shape Stripe still includes.
     *
     * @param  array<string, mixed>  $source
     */
    private function planLabel(array $source): ?string
    {
        $price = $source['items']['data'][0]['price'] ?? $source['plan'] ?? null;

        if (! is_array($price)) {
            return null;
        }

        $amount = $price['unit_amount'] ?? $price['amount'] ?? null;

        if ($amount === null) {
            return null;
        }

        $label = Money::format((int) $amount, (string) ($price['currency'] ?? 'usd'));
        $recurring = is_array($price['recurring'] ?? null) ? $price['recurring'] : $price;

        if (! isset($recurring['interval'])) {
            return $label;
        }

        $count = (int) ($recurring['interval_count'] ?? 1);
        $interval = $count > 1 ? sprintf('%d %ss', $count, $recurring['interval']) : (string) $recurring['interval'];

        return sprintf('%s / %s', $label, $interval);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function cancellationReason(array $object): ?string
    {
        $reason = $object['cancellation_details']['reason'] ?? null;

        return is_string($reason) ? str_replace('_', ' ', $reason) : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return now()->setTimestamp((int) $value)->toDayDateTimeString();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $name, string $value, bool $inline): array
    {
        return ['name' => $name, 'value' => $value, 'inline' => $inline];
    }
}
