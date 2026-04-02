<?php

namespace App\Services;

class CurrencyOptions
{
    /**
     * @return list<array{code: string, name: string, allows_primary: bool, allows_account: bool}>
     */
    public function all(): array
    {
        /** @var list<array{code: string, name: string, allows_primary: bool, allows_account: bool}> $options */
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
     * @param  list<array{code: string, name: string, allows_primary: bool, allows_account: bool}>  $options
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
