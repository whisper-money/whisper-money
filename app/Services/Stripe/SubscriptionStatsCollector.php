<?php

namespace App\Services\Stripe;

use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription;

class SubscriptionStatsCollector
{
    /**
     * @return array{active: array<string, array{count: int, mrr: float}>, trialing: array<string, array{count: int, mrr: float}>}
     *
     * @throws ApiErrorException
     */
    public function collect(): array
    {
        return [
            'active' => $this->collectStatus('active'),
            'trialing' => $this->collectStatus('trialing'),
        ];
    }

    /**
     * @return array<string, array{count: int, mrr: float}>
     *
     * @throws ApiErrorException
     */
    private function collectStatus(string $status): array
    {
        $bucket = [];

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

        return $bucket;
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
}
