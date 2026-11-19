<?php

namespace App\Services;

use Illuminate\Database\QueryException;
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

    /**
     * The first day the sources publish a dated release (the primary CDN;
     * the day before answers 404 on both hosts). Nothing earlier will ever
     * exist, so dates before it are answered without a request.
     */
    private const FIRST_RELEASE_DATE = '2024-03-02';

    /**
     * How old a date must be before a release missing from every source is
     * taken as a permanent gap rather than one not published yet.
     */
    private const MISSING_RELEASE_SETTLED_AFTER_DAYS = 7;

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

        if ($this->predatesFirstRelease($date)) {
            return $this->rateCache[$cacheKey] = [];
        }

        $persistentKey = "currency-rates:{$cacheKey}";

        $rates = Cache::get($persistentKey);

        if ($rates === null) {
            ['rates' => $rates, 'onlyNotFound' => $onlyNotFound] = $this->fetchRates($currency, $date);
            $this->cacheRates($persistentKey, $rates, $this->cacheTtlFor($date, $rates, $onlyNotFound));
        }

        $this->rateCache[$cacheKey] = $rates;

        return $rates;
    }

    /**
     * Persist a fetched rate map, best-effort.
     *
     * The database cache store turns a write into an `insert ignore into
     * cache`, so concurrent requests missing the same hot rate key race for
     * the same row and InnoDB deadlocks one of them. The rates are already in
     * hand by then, so losing that race is no reason to fail the request: the
     * request that won it populated the row, and the next miss re-attempts the
     * write anyway.
     *
     * Any write failure is swallowed, not only the deadlock: each one costs a
     * value the next miss re-fetches, so none of them is worth a 500. Only the
     * write is guarded — a structurally broken cache backend still surfaces,
     * because the read a few lines above is not.
     *
     * @param  array<string, float>  $rates
     */
    private function cacheRates(string $persistentKey, array $rates, int $ttlSeconds): void
    {
        try {
            Cache::put($persistentKey, $rates, $ttlSeconds);
        } catch (QueryException $e) {
            Log::warning('Currency rate cache write failed', [
                'key' => $persistentKey,
                'error' => $e->getMessage(),
            ]);
        }
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
     * `onlyNotFound` tells whether every source answered 404 for every
     * candidate date, as opposed to failing on the way (a connection error
     * or a 5xx), which decides how long an empty result may be cached.
     *
     * @return array{rates: array<string, float>, onlyNotFound: bool}
     */
    private function fetchRates(string $currency, string $date): array
    {
        $sourceUnreachable = false;
        $onlyNotFound = true;

        foreach ($this->candidateDates($date) as $candidateDate) {
            foreach ($this->rateUrls($currency, $candidateDate) as $url) {
                try {
                    $response = Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                        ->timeout(self::HTTP_TIMEOUT_SECONDS)
                        ->get($url);
                } catch (ConnectionException $e) {
                    $sourceUnreachable = true;
                    $onlyNotFound = false;

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
                    return ['rates' => $response->json($currency) ?? [], 'onlyNotFound' => false];
                }

                $onlyNotFound = false;

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

        return ['rates' => [], 'onlyNotFound' => $onlyNotFound];
    }

    /**
     * Resolve the cache lifetime for a fetched rate map.
     *
     * Historical releases are immutable, so cache them long. The "latest"
     * release changes daily. An empty result is cached briefly so a transient
     * outage, or a release not published yet, recovers quickly — unless every
     * source answered 404 for a date old enough that the gap is permanent,
     * which would otherwise repeat the whole lookback walk every few minutes.
     *
     * @param  array<string, float>  $rates
     */
    private function cacheTtlFor(string $date, array $rates, bool $onlyNotFound): int
    {
        if ($rates === []) {
            return $onlyNotFound && $this->isSettledHistoricalDate($date)
                ? self::CACHE_TTL_HISTORICAL_SECONDS
                : self::CACHE_TTL_UNAVAILABLE_SECONDS;
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
            ->reject(fn (string $candidateDate): bool => $this->predatesFirstRelease($candidateDate))
            ->values()
            ->all();
    }

    private function predatesFirstRelease(string $date): bool
    {
        return $date !== 'latest' && $date < self::FIRST_RELEASE_DATE;
    }

    private function isSettledHistoricalDate(string $date): bool
    {
        return $date !== 'latest'
            && $date <= Carbon::today()->subDays(self::MISSING_RELEASE_SETTLED_AFTER_DAYS)->toDateString();
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
