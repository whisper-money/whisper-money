<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /** @var array<string, array<string, float>> */
    private array $ratesCache = [];

    /** @var array<string, true> */
    private array $databaseMissCache = [];

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

        if ($source === $target || $amountInCents === 0) {
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
        $date = $this->normalizeDate($date);
        $cacheKey = $this->cacheKey($baseCurrency, $date);

        if (array_key_exists($cacheKey, $this->ratesCache)) {
            return $this->ratesCache[$cacheKey];
        }

        if (! isset($this->databaseMissCache[$cacheKey])) {
            $cached = ExchangeRate::query()
                ->where('base_currency', $baseCurrency)
                ->where('date', $date)
                ->first();

            if ($cached) {
                return $this->ratesCache[$cacheKey] = $cached->rates;
            }
        }

        $rates = $this->currencyApi->getRatesForCurrency($baseCurrency, $date);

        if (! empty($rates)) {
            ExchangeRate::query()->create([
                'base_currency' => $baseCurrency,
                'date' => $date,
                'rates' => $rates,
            ]);
        }

        return $this->ratesCache[$cacheKey] = $rates;
    }

    /**
     * Preload cached exchange rates for many dates in one query.
     *
     * @param  iterable<int, string>  $dates
     */
    public function preloadRates(string $baseCurrency, iterable $dates): void
    {
        $baseCurrency = strtolower($baseCurrency);
        $dates = collect($dates)
            ->map(fn (string $date): string => $this->normalizeDate($date))
            ->unique()
            ->reject(fn (string $date): bool => array_key_exists($this->cacheKey($baseCurrency, $date), $this->ratesCache))
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        ExchangeRate::query()
            ->where('base_currency', $baseCurrency)
            ->whereIn('date', $dates->all())
            ->get(['base_currency', 'date', 'rates'])
            ->each(function (ExchangeRate $exchangeRate): void {
                $date = $exchangeRate->date->toDateString();
                $this->ratesCache[$this->cacheKey($exchangeRate->base_currency, $date)] = $exchangeRate->rates;
            });

        $dates
            ->reject(fn (string $date): bool => array_key_exists($this->cacheKey($baseCurrency, $date), $this->ratesCache))
            ->each(function (string $date) use ($baseCurrency): void {
                $this->databaseMissCache[$this->cacheKey($baseCurrency, $date)] = true;
            });
    }

    private function normalizeDate(string $date): string
    {
        $today = Carbon::today()->toDateString();

        return $date > $today ? $today : $date;
    }

    private function cacheKey(string $baseCurrency, string $date): string
    {
        return strtolower($baseCurrency).'|'.$date;
    }
}
