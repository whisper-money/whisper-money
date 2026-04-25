<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Stripe\Coupon;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;

class EnsureLaunchCouponsCommand extends Command
{
    public const COUPON_FOUNDER_FOREVER = 'wm_founder_forever';

    public const COUPON_EARLYBIRD_MONTHLY = 'wm_earlybird_monthly';

    public const COUPON_EARLYBIRD_YEARLY = 'wm_earlybird_yearly';

    protected $signature = 'stripe:ensure-launch-coupons {--dry-run : Show what would happen without writing to Stripe}';

    protected $description = 'Idempotently create the launch invitation coupons in Stripe';

    public function handle(): int
    {
        $stripe = Cashier::stripe();
        $dryRun = (bool) $this->option('dry-run');

        $monthlyPriceId = $this->resolvePriceId(config('subscriptions.plans.monthly.stripe_lookup_key'));
        $yearlyPriceId = $this->resolvePriceId(config('subscriptions.plans.yearly.stripe_lookup_key'));

        if ($monthlyPriceId === null || $yearlyPriceId === null) {
            $this->error('Unable to resolve monthly/yearly Stripe price IDs. Run `php artisan stripe:sync-prices` first.');

            return self::FAILURE;
        }

        $monthlyProductId = $stripe->prices->retrieve($monthlyPriceId)->product;
        $yearlyProductId = $stripe->prices->retrieve($yearlyPriceId)->product;

        $coupons = [
            [
                'id' => self::COUPON_FOUNDER_FOREVER,
                'name' => 'WM Founder – 100% off forever',
                'percent_off' => 100,
                'duration' => 'forever',
                'applies_to' => null,
            ],
            [
                'id' => self::COUPON_EARLYBIRD_MONTHLY,
                'name' => 'WM Early Bird – 2 months free',
                'percent_off' => 100,
                'duration' => 'repeating',
                'duration_in_months' => 2,
                'applies_to' => ['products' => [$monthlyProductId]],
            ],
            [
                'id' => self::COUPON_EARLYBIRD_YEARLY,
                'name' => 'WM Early Bird – 25% off year 1',
                'percent_off' => 25,
                'duration' => 'once',
                'applies_to' => ['products' => [$yearlyProductId]],
            ],
        ];

        foreach ($coupons as $config) {
            $existing = $this->findCoupon($config['id']);

            if ($existing !== null) {
                $this->info("✔ Coupon `{$config['id']}` already exists.");

                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] Would create coupon `{$config['id']}`.");

                continue;
            }

            $payload = array_filter([
                'id' => $config['id'],
                'name' => $config['name'],
                'percent_off' => $config['percent_off'],
                'duration' => $config['duration'],
                'duration_in_months' => $config['duration_in_months'] ?? null,
                'applies_to' => $config['applies_to'] ?? null,
            ], fn ($value): bool => $value !== null);

            try {
                $stripe->coupons->create($payload);
            } catch (ApiErrorException $exception) {
                $this->error("Failed creating `{$config['id']}`: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $this->info("+ Created coupon `{$config['id']}`.");
        }

        return self::SUCCESS;
    }

    private function findCoupon(string $id): ?Coupon
    {
        try {
            return Cashier::stripe()->coupons->retrieve($id);
        } catch (InvalidRequestException) {
            return null;
        }
    }

    private function resolvePriceId(?string $lookupKey): ?string
    {
        if ($lookupKey === null || $lookupKey === '') {
            return null;
        }

        $prices = Cashier::stripe()->prices->all([
            'lookup_keys' => [$lookupKey],
            'limit' => 1,
            'expand' => ['data.product'],
        ]);

        return $prices->data[0]->id ?? null;
    }
}
