<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CurrencyConversionService
{
    private const PRIMARY_URL = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@';

    private const FALLBACK_URL = 'https://currency-api.pages.dev/v1/';

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
     * Fetch rates for a target currency, using in-memory cache.
     *
     * @return array<string, float> Map of currency code => rate (e.g., "btc" => 0.000015)
     */
    private function getRatesForCurrency(string $currency, string $date): array
    {
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
        $primaryUrl = self::PRIMARY_URL."{$date}/v1/currencies/{$currency}.min.json";
        $fallbackUrl = self::FALLBACK_URL."{$date}/currencies/{$currency}.min.json";

        try {
            $response = Http::timeout(10)->get($primaryUrl);
            $response->throw();

            return $response->json($currency) ?? [];
        } catch (\Throwable) {
            // Primary failed, try fallback
        }

        try {
            $response = Http::timeout(10)->get($fallbackUrl);
            $response->throw();

            return $response->json($currency) ?? [];
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to fetch currency rates for {$currency} on {$date}: {$e->getMessage()}", 0, $e);
        }
    }
}
