<?php

return [
    /*
     * Every money value is stored as an integer in the currency's minor units,
     * and `decimals` is that scale: EUR 2 (cents), COP 0 (no centavos in
     * practice), BTC 8 (satoshis). Currencies omit the key when the scale is
     * the default 2, so only the exceptions are spelled out.
     *
     * The scale decides how values are *stored*, so it must never be derived
     * from the host's ICU version: `NumberFormatter` reads PKR as 0 decimals on
     * macOS and 2 on Linux, which would make one row mean two different amounts
     * and an ICU upgrade a silent data migration. `CurrencyDecimalsTest` pins
     * these values against its own table, so editing this file without a
     * rescaling migration turns it red.
     */
    'options' => [
        [
            'code' => 'USD',
            'name' => 'US Dollar',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'EUR',
            'name' => 'Euro',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'GBP',
            'name' => 'British Pound',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'JPY',
            'name' => 'Japanese Yen',
            'allows_primary' => true,
            'allows_account' => true,
            'decimals' => 0,
        ],
        [
            'code' => 'CHF',
            'name' => 'Swiss Franc',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'CZK',
            'name' => 'Czech Koruna',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'CAD',
            'name' => 'Canadian Dollar',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'AUD',
            'name' => 'Australian Dollar',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'NZD',
            'name' => 'New Zealand Dollar',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'CNY',
            'name' => 'Chinese Yuan',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'INR',
            'name' => 'Indian Rupee',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'PKR',
            'name' => 'Pakistani Rupee',
            'allows_primary' => true,
            'allows_account' => true,
            'decimals' => 0,
        ],
        [
            'code' => 'MXN',
            'name' => 'Mexican Peso',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'ARS',
            'name' => 'Argentine Peso',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'BOB',
            'name' => 'Bolivian Boliviano',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'CLP',
            'name' => 'Chilean Peso',
            'allows_primary' => true,
            'allows_account' => true,
            'decimals' => 0,
        ],
        [
            'code' => 'PYG',
            'name' => 'Paraguayan Guarani',
            'allows_primary' => true,
            'allows_account' => true,
            'decimals' => 0,
        ],
        [
            'code' => 'PEN',
            'name' => 'Peruvian Sol',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'UYU',
            'name' => 'Uruguayan Peso',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'VES',
            'name' => 'Venezuelan Bolívar',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'BRL',
            'name' => 'Brazilian Real',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'COP',
            'name' => 'Colombian Peso',
            'allows_primary' => true,
            'allows_account' => true,
            'decimals' => 0,
        ],
        [
            'code' => 'DOP',
            'name' => 'Dominican Peso',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'SAR',
            'name' => 'Saudi Riyal',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'KWD',
            'name' => 'Kuwaiti Dinar',
            'allows_primary' => true,
            'allows_account' => true,
            'decimals' => 3,
        ],
        [
            'code' => 'BTC',
            'name' => 'Bitcoin',
            'allows_primary' => false,
            'allows_account' => true,
            'decimals' => 8,
        ],
        [
            'code' => 'RSD',
            'name' => 'Serbian Dinar',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'NGN',
            'name' => 'Nigerian Naira',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'GHS',
            'name' => 'Ghanaian Cedi',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'SEK',
            'name' => 'Swedish Krona',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'GTQ',
            'name' => 'Guatemalan Quetzal',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'HNL',
            'name' => 'Honduran Lempira',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'HKD',
            'name' => 'Hong Kong Dollar',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'THB',
            'name' => 'Thai Baht',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'DKK',
            'name' => 'Danish Krone',
            'allows_primary' => true,
            'allows_account' => true,
        ],
        [
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'allows_primary' => true,
            'allows_account' => true,
        ],
    ],
];
