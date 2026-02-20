<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    public function __construct(private CurrencyConversionService $currencyApi) {}

    /**
     * Convert an amount in cents from one currency to another on a given date.
     *
     * Uses DB-cached exchange rates, falling back to the external API on cache miss.
     *
     * @param  string  $source  Source currency code (e.g., "EUR")
     * @param  string  $target  Target currency code (e.g., "USD")
     * @param  int  $amountInCents  Amount in the source currency's smallest unit
     * @param  string  $date  Date string (YYYY-MM-DD)
     */
    public function convert(string $source, string $target, int $amountInCents, string $date): int
    {
        $source = strtolower($source);
        $target = strtolower($target);

        if ($source === $target) {
            return $amountInCents;
        }

        $rates = $this->getRates($target, $date);

        if (! isset($rates[$source]) || $rates[$source] == 0) {
            Log::warning('Exchange rate not found, returning unconverted amount', [
                'source' => $source,
                'target' => $target,
                'date' => $date,
            ]);

            return $amountInCents;
        }

        return (int) round($amountInCents / $rates[$source]);
    }

    /**
     * Get all exchange rates for a base currency on a given date.
     *
     * Checks the DB cache first, then fetches from the external API
     * and stores the result for future lookups.
     *
     * @return array<string, float>
     */
    public function getRates(string $baseCurrency, string $date): array
    {
        $baseCurrency = strtolower($baseCurrency);

        // Cap future dates to today — the API only has rates up to the current date.
        $today = Carbon::today()->toDateString();
        if ($date > $today) {
            $date = $today;
        }

        $cached = ExchangeRate::query()
            ->where('base_currency', $baseCurrency)
            ->where('date', $date)
            ->first();

        if ($cached) {
            return $cached->rates;
        }

        $rates = $this->currencyApi->getRatesForCurrency($baseCurrency, $date);

        if (! empty($rates)) {
            ExchangeRate::query()->create([
                'base_currency' => $baseCurrency,
                'date' => $date,
                'rates' => $rates,
            ]);
        }

        return $rates;
    }
}
