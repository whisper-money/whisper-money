<?php

namespace App\Console\Commands;

use App\Support\Money;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;

class SyncStripePricesCommand extends Command
{
    protected $signature = 'stripe:sync-prices
        {--dry-run : Preview changes without creating anything in Stripe}';

    protected $description = 'Create or update Stripe prices from config/subscriptions.php using lookup keys';

    public function handle(): int
    {
        $plans = config('subscriptions.plans', []);
        $currency = config('cashier.currency', 'eur');
        $dryRun = $this->option('dry-run');

        if (empty($plans)) {
            $this->warn('No plans found in config/subscriptions.php.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Running in dry-run mode — no changes will be made to Stripe.');
        }

        $this->info('Syncing Stripe prices from config...');
        $this->newLine();

        $created = 0;
        $transferred = 0;
        $skipped = 0;

        foreach ($plans as $planKey => $plan) {
            $lookupKey = $plan['stripe_lookup_key'] ?? null;

            if (! $lookupKey) {
                $this->warn("  [{$planKey}] No stripe_lookup_key defined — skipping.");
                $skipped++;

                continue;
            }

            $amountInCents = (int) round($plan['price'] * 100);
            $billingPeriod = $plan['billing_period'] ?? null;
            $productId = config('subscriptions.products.pro');
            $formattedAmount = Money::format($amountInCents, $currency);

            $this->line("  <options=bold>{$plan['name']}</>");

            try {
                $existing = $this->findPriceByLookupKey($lookupKey);

                if ($existing) {
                    if ($this->priceMatches($existing, $amountInCents, $currency, $billingPeriod)) {
                        $this->line("    <fg=green>✓</> Already in sync ({$existing->id})");
                        $skipped++;

                        continue;
                    }

                    $this->line('    <fg=yellow>↻</> Price changed — creating new price and transferring lookup key...');

                    if (! $dryRun) {
                        $price = $this->createPrice($productId, $amountInCents, $currency, $billingPeriod, $lookupKey, transferLookupKey: true);
                        $this->line("    <fg=green>✓</> Transferred to {$price->id} ({$formattedAmount}/{$billingPeriod})");
                    } else {
                        $this->line("    <fg=cyan>[dry-run]</> Would create new price and transfer lookup key '{$lookupKey}'");
                    }

                    $transferred++;
                } else {
                    $this->line('    <fg=yellow>+</> No existing price — creating...');

                    if (! $dryRun) {
                        $price = $this->createPrice($productId, $amountInCents, $currency, $billingPeriod, $lookupKey, transferLookupKey: false);
                        $this->line("    <fg=green>✓</> Created {$price->id} ({$formattedAmount}/{$billingPeriod})");
                    } else {
                        $this->line("    <fg=cyan>[dry-run]</> Would create price '{$lookupKey}' at {$formattedAmount}/{$billingPeriod}");
                    }

                    $created++;
                }
            } catch (ApiErrorException $e) {
                $this->error("    ✗ Stripe API error: {$e->getMessage()}");

                return Command::FAILURE;
            }
        }

        $this->newLine();

        $suffix = $dryRun ? ' (dry-run)' : '';
        $this->info("{$created} created, {$transferred} updated, {$skipped} skipped{$suffix}.");

        return Command::SUCCESS;
    }

    /**
     * @return Price|null
     */
    private function findPriceByLookupKey(string $lookupKey): mixed
    {
        $response = Cashier::stripe()->prices->all(['lookup_keys' => [$lookupKey], 'limit' => 1]);

        return $response->data[0] ?? null;
    }

    /**
     * @throws ApiErrorException
     */
    private function createPrice(
        string $productId,
        int $amountInCents,
        string $currency,
        ?string $billingPeriod,
        string $lookupKey,
        bool $transferLookupKey,
    ): Price {
        $params = [
            'product' => $productId,
            'unit_amount' => $amountInCents,
            'currency' => $currency,
            'lookup_key' => $lookupKey,
            'transfer_lookup_key' => $transferLookupKey,
        ];

        if ($billingPeriod) {
            $params['recurring'] = ['interval' => $billingPeriod];
        }

        return Cashier::stripe()->prices->create($params);
    }

    /**
     * @param  Price  $price
     */
    private function priceMatches(mixed $price, int $amountInCents, string $currency, ?string $billingPeriod): bool
    {
        $currencyMatches = strtolower((string) $price->currency) === strtolower($currency);
        $amountMatches = $price->unit_amount === $amountInCents;
        $intervalMatches = $price->recurring?->interval === $billingPeriod;

        return $currencyMatches && $amountMatches && $intervalMatches;
    }
}
