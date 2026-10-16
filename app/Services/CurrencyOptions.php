<?php

namespace App\Services;

class CurrencyOptions
{
    /**
     * The scale every currency uses unless `config/currencies.php` says
     * otherwise, and the scale every row in the database predates this key with.
     */
    public const DEFAULT_DECIMALS = 2;

    /**
     * How many decimals a currency's minor unit has: EUR 2, COP 0, BTC 8. This
     * is the scale money is *stored* at, not just displayed at, so an unknown
     * code falls back to the 2 every existing row already uses.
     */
    public function decimals(string $code): int
    {
        return $this->decimalsMap()[strtoupper($code)] ?? self::DEFAULT_DECIMALS;
    }

    /**
     * Every currency's scale, spelled out including the defaults, so the
     * frontend reads one table instead of deriving its own from CLDR.
     *
     * @return array<string, int>
     */
    public function decimalsMap(): array
    {
        $map = [];

        foreach ($this->all() as $currency) {
            $map[$currency['code']] = $currency['decimals'] ?? self::DEFAULT_DECIMALS;
        }

        return $map;
    }

    /**
     * Currency codes grouped by the scale they share, for queries that have to
     * compare one major-unit threshold against columns at several scales.
     *
     * @return array<int, list<string>>
     */
    public function codesByDecimals(): array
    {
        $grouped = [];

        foreach ($this->decimalsMap() as $code => $decimals) {
            $grouped[$decimals][] = $code;
        }

        return $grouped;
    }

    /**
     * The currency to quote a reader in, off the region half of their
     * `format_locale`. USD for a region `config/currencies.php` maps no
     * currency for, which is what the column defaults to anyway.
     */
    public function forFormatLocale(?string $formatLocale): string
    {
        return $this->forRegion(substr((string) $formatLocale, -2)) ?? 'USD';
    }

    /**
     * The currency of the country a timezone sits in — "Asia/Kolkata" is INR —
     * or null for a zone with no country (UTC) or a country we map no currency
     * for. A signup's browser timezone is a far better hint than a header
     * that only ever said "en-US" because that is what the OS shipped with.
     */
    public function forTimezone(?string $timezone): ?string
    {
        if ($timezone === null || $timezone === '') {
            return null;
        }

        return $this->forRegion($this->countryOfTimezone($timezone));
    }

    /**
     * PHP's tzdata knows the country of every canonical zone but answers "??"
     * for a legacy alias like "Asia/Calcutta", which older browsers still
     * send, so those are asked of ICU instead.
     */
    private function countryOfTimezone(string $timezone): ?string
    {
        try {
            $location = (new \DateTimeZone($timezone))->getLocation();
        } catch (\Exception) {
            return null;
        }

        if ($location !== false && $location['country_code'] !== '??') {
            return $location['country_code'];
        }

        if (! class_exists(\IntlTimeZone::class)) {
            return null;
        }

        return \IntlTimeZone::getRegion($timezone) ?: null;
    }

    /**
     * The currency `config/currencies.php` maps a two-letter region to.
     */
    public function forRegion(?string $region): ?string
    {
        /** @var array<string, string> $byRegion */
        $byRegion = config('currencies.by_region', []);

        return $byRegion[strtoupper((string) $region)] ?? null;
    }

    /**
     * @return list<array{code: string, name: string, allows_primary: bool, allows_account: bool, decimals?: int}>
     */
    public function all(): array
    {
        /** @var list<array{code: string, name: string, allows_primary: bool, allows_account: bool, decimals?: int}> $options */
        $options = config('currencies.options', []);

        return $options;
    }

    /**
     * @return list<string>
     */
    public function primaryCodes(): array
    {
        return array_values(array_map(
            fn (array $currency): string => $currency['code'],
            array_filter($this->all(), fn (array $currency): bool => $currency['allows_primary'])
        ));
    }

    /**
     * @return list<string>
     */
    public function accountCodes(): array
    {
        return array_values(array_map(
            fn (array $currency): string => $currency['code'],
            array_filter($this->all(), fn (array $currency): bool => $currency['allows_account'])
        ));
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function primaryOptions(): array
    {
        return $this->formatOptions($this->all(), 'allows_primary');
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function accountOptions(): array
    {
        return $this->formatOptions($this->all(), 'allows_account');
    }

    /**
     * @param  list<array{code: string, name: string, allows_primary: bool, allows_account: bool, decimals?: int}>  $options
     * @return list<array{code: string, name: string}>
     */
    private function formatOptions(array $options, string $capability): array
    {
        return array_values(array_map(
            fn (array $currency): array => [
                'code' => $currency['code'],
                'name' => __($currency['name']),
            ],
            array_filter($options, fn (array $currency): bool => $currency[$capability])
        ));
    }
}
