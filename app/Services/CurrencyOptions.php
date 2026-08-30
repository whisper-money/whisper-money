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
