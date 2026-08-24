<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyConversionService
{
    private const PRIMARY_URL = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@';

    private const FALLBACK_URL = 'https://currency-api.pages.dev/v1/';

    private const HISTORICAL_LOOKBACK_DAYS = 7;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 3;

    private const HTTP_TIMEOUT_SECONDS = 5;

    private const CACHE_TTL_HISTORICAL_SECONDS = 60 * 60 * 24 * 30;

    private const CACHE_TTL_LATEST_SECONDS = 60 * 60 * 6;

    private const CACHE_TTL_UNAVAILABLE_SECONDS = 60 * 10;

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

        $persistentKey = "currency-rates:{$cacheKey}";

        $rates = Cache::get($persistentKey);

        if ($rates === null) {
            $rates = $this->fetchRates($currency, $date);
            Cache::put($persistentKey, $rates, $this->cacheTtlFor($date, $rates));
        }

        $this->rateCache[$cacheKey] = $rates;

        return $rates;
    }

    /**
     * Fetch rates from CDN with fallback.
     *
     * A missing release (404) walks back to earlier historical dates, but an
     * unreachable source (connection refused or timeout) aborts the walk: the
     * same timeout would repeat for every candidate date and risk exhausting
     * the request's execution time. Failures degrade to an empty rate map
     * rather than throwing, so a slow CDN never crashes the calling endpoint.
     *
     * @return array<string, float>
     */
    private function fetchRates(string $currency, string $date): array
    {
        $sourceUnreachable = false;

        foreach ($this->candidateDates($date) as $candidateDate) {
            foreach ($this->rateUrls($currency, $candidateDate) as $url) {
                try {
                    $response = Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                        ->timeout(self::HTTP_TIMEOUT_SECONDS)
                        ->get($url);
                } catch (ConnectionException $e) {
                    $sourceUnreachable = true;

                    Log::warning('Currency rate source unreachable', [
                        'currency' => $currency,
                        'date' => $candidateDate,
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                if ($response->notFound()) {
                    continue;
                }

                if ($response->successful()) {
                    return $response->json($currency) ?? [];
                }

                Log::warning('Currency rate source returned an error', [
                    'currency' => $currency,
                    'date' => $candidateDate,
                    'status' => $response->status(),
                ]);
            }

            if ($sourceUnreachable) {
                break;
            }
        }

        Log::warning('Currency rates unavailable', [
            'currency' => $currency,
            'date' => $date,
        ]);

        return [];
    }

    /**
     * Resolve the cache lifetime for a fetched rate map.
     *
     * Historical releases are immutable, so cache them long. The "latest"
     * release changes daily. An empty result means the sources were missing or
     * unreachable; cache it briefly so a transient outage recovers quickly.
     *
     * @param  array<string, float>  $rates
     */
    private function cacheTtlFor(string $date, array $rates): int
    {
        if ($rates === []) {
            return self::CACHE_TTL_UNAVAILABLE_SECONDS;
        }

        return $date === 'latest'
            ? self::CACHE_TTL_LATEST_SECONDS
            : self::CACHE_TTL_HISTORICAL_SECONDS;
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
