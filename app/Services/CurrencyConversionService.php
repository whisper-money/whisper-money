<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CurrencyConversionService
{
    private const PRIMARY_URL = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@';

    private const FALLBACK_URL = 'https://currency-api.pages.dev/v1/';

    private const HISTORICAL_LOOKBACK_DAYS = 7;

    /** @var array<string, array<string, float>> Keyed by "{currency}:{date}" */
    private array $rateCache = [];

    /**
     * Convert a quantity from one currency to another on a given date.
     *
     * @param  string  $source  Source currency code (e.g., "btc", "eth", "usd")
     * @param  string  $target  Target currency code (e.g., "eur", "usd")
     * @param  float  $quantity  Amount to convert
     * @param  string  $date  Date string (YYYY-MM-DD) or "latest"
     */
    public function convert(string $source, string $target, float $quantity, string $date = 'latest'): float
    {
        $source = strtolower($source);
        $target = strtolower($target);

        if ($source === $target) {
            return $quantity;
        }

        $rates = $this->getRatesForCurrency($target, $date);

        if (! isset($rates[$source]) || $rates[$source] == 0) {
            Log::debug('Currency rate not found', [
                'source' => $source,
                'target' => $target,
                'date' => $date,
            ]);

            return 0.0;
        }

        return $quantity / $rates[$source];
    }

    /**
     * Fetch all rates for a base currency on a given date.
     *
     * Returns a map of currency code => rate relative to the base currency.
     * Results are cached in-memory for the duration of the request.
     *
     * @return array<string, float>
     */
    public function getRatesForCurrency(string $currency, string $date): array
    {
        $currency = strtolower($currency);
        $cacheKey = "{$currency}:{$date}";

        if (isset($this->rateCache[$cacheKey])) {
            return $this->rateCache[$cacheKey];
        }

        $rates = $this->fetchRates($currency, $date);
        $this->rateCache[$cacheKey] = $rates;

        return $rates;
    }

    /**
     * Fetch rates from CDN with fallback.
     *
     * @return array<string, float>
     */
    private function fetchRates(string $currency, string $date): array
    {
        $lastException = null;

        foreach ($this->candidateDates($date) as $candidateDate) {
            foreach ($this->rateUrls($currency, $candidateDate) as $url) {
                try {
                    $response = Http::timeout(10)->get($url);
                    $response->throw();

                    return $response->json($currency) ?? [];
                } catch (\Throwable $e) {
                    $lastException = $e;
                }
            }
        }

        throw new RuntimeException("Failed to fetch currency rates for {$currency} on {$date}: {$lastException?->getMessage()}", 0, $lastException);
    }

    /**
     * @return array<int, string>
     */
    private function candidateDates(string $date): array
    {
        if ($date === 'latest') {
            return [$date];
        }

        $parsedDate = Carbon::createFromFormat('Y-m-d', $date);

        return collect(range(0, self::HISTORICAL_LOOKBACK_DAYS))
            ->map(fn (int $days): string => $parsedDate->copy()->subDays($days)->toDateString())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function rateUrls(string $currency, string $date): array
    {
        return [
            self::PRIMARY_URL."{$date}/v1/currencies/{$currency}.min.json",
            self::FALLBACK_URL."{$date}/currencies/{$currency}.min.json",
        ];
    }
}
