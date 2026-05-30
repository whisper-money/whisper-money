<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription;

class StripeSubscriptionStatsCommand extends Command
{
    protected $signature = 'stripe:subscription-stats';

    protected $description = 'Show Stripe subscription stats: active/trialing counts and current/projected MRR & ARR';

    /**
     * @var array<string, array{count: int, mrr: float}>
     */
    private array $active = [];

    /**
     * @var array<string, array{count: int, mrr: float}>
     */
    private array $trialing = [];

    public function handle(): int
    {
        try {
            $this->collect('active', $this->active);
            $this->collect('trialing', $this->trialing);
        } catch (ApiErrorException $exception) {
            $this->error("Stripe API error: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->render();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{count: int, mrr: float}>  $bucket
     *
     * @throws ApiErrorException
     */
    private function collect(string $status, array &$bucket): void
    {
        $subscriptions = Cashier::stripe()->subscriptions->all([
            'status' => $status,
            'limit' => 100,
            'expand' => ['data.items.data.price'],
        ]);

        /** @var Subscription $subscription */
        foreach ($subscriptions->autoPagingIterator() as $subscription) {
            $currency = strtolower((string) $subscription->currency);

            $bucket[$currency] ??= ['count' => 0, 'mrr' => 0.0];
            $bucket[$currency]['count']++;
            $bucket[$currency]['mrr'] += $this->monthlyValue($subscription);
        }
    }

    private function monthlyValue(Subscription $subscription): float
    {
        $monthly = 0.0;

        foreach ($subscription->items->data as $item) {
            $price = $item->price;

            if ($price->recurring === null) {
                continue;
            }

            $amount = ($price->unit_amount ?? 0) / 100;
            $quantity = $item->quantity ?? 1;
            $intervalCount = $price->recurring->interval_count ?: 1;

            $perMonth = match ($price->recurring->interval) {
                'day' => $amount * 365 / 12,
                'week' => $amount * 52 / 12,
                'month' => $amount,
                'year' => $amount / 12,
                default => 0.0,
            };

            $monthly += ($perMonth / $intervalCount) * $quantity;
        }

        return $monthly;
    }

    private function render(): void
    {
        $currencies = array_unique(array_merge(array_keys($this->active), array_keys($this->trialing)));
        sort($currencies);

        if ($currencies === []) {
            $this->warn('No active or trialing subscriptions found.');

            return;
        }

        foreach ($currencies as $currency) {
            $active = $this->active[$currency] ?? ['count' => 0, 'mrr' => 0.0];
            $trialing = $this->trialing[$currency] ?? ['count' => 0, 'mrr' => 0.0];

            $currentMrr = $active['mrr'];
            $projectedMrr = $active['mrr'] + $trialing['mrr'];

            $this->newLine();
            $this->line('<options=bold>'.strtoupper($currency).'</>');
            $this->line("  Active subs:    <fg=green>{$active['count']}</> ({$this->format($active['mrr'], $currency)} MRR)");
            $this->line("  Trialing subs:  <fg=yellow>{$trialing['count']}</> ({$this->format($trialing['mrr'], $currency)} MRR)");
            $this->newLine();
            $this->line("  Current MRR:    <fg=cyan>{$this->format($currentMrr, $currency)}</>");
            $this->line("  Current ARR:    <fg=cyan>{$this->format($currentMrr * 12, $currency)}</>");
            $this->line("  Projected MRR:  <fg=cyan>{$this->format($projectedMrr, $currency)}</> (if trialing convert)");
            $this->line("  Projected ARR:  <fg=cyan>{$this->format($projectedMrr * 12, $currency)}</>");
        }

        $this->newLine();
    }

    private function format(float $amount, string $currency): string
    {
        $symbol = match (strtolower($currency)) {
            'eur' => '€',
            'gbp' => '£',
            'usd' => '$',
            'jpy' => '¥',
            'brl' => 'R$',
            default => strtoupper($currency).' ',
        };

        return $symbol.number_format($amount, 2);
    }
}
