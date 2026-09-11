<?php

return [
    /*
     * The locale a reader's amounts and dates are written in — a full region
     * like `es-MX`, and a different thing from `locale`, which only picks the
     * language the app is translated into. A Mexican reading Spanish still
     * wants "1,234.56", not Spain's "1.234,56".
     *
     * One entry per country whose currency `config/currencies.php` offers, so
     * anybody the app can hold money for can be written to properly. The euro
     * is the one currency with several homes; BTC has none. Plus `es-419`,
     * which is not a country at all — see below.
     *
     * These are CLDR's rules for the country as they come, which for three of
     * them means more than a separator moving: `th-TH` writes the Buddhist era
     * (2569 for 2026), and `ar-SA`/`ar-KW` write Arabic-Indic digits
     * (٩ for 9). That is what a phone sold in Bangkok or Riyadh shows, and only
     * a reader whose own browser asks for one of these is ever given it.
     */
    'options' => [
        'ar-KW', // KWD
        'ar-SA', // SAR
        'cs-CZ', // CZK
        'da-DK', // DKK
        'de-CH', // CHF
        'de-DE', // EUR
        'en-AU', // AUD
        'en-CA', // CAD
        'en-GB', // GBP
        'en-GH', // GHS
        'en-IE', // EUR
        'en-IN', // INR
        'en-NG', // NGN
        'en-NZ', // NZD
        'en-PK', // PKR
        'en-SG', // SGD
        'en-US', // USD
        // Not a country: the tag Chrome and Android send for "Spanish (Latin
        // America)", which is what a great many of the readers this change is
        // for actually have set. Without it they fall through to Spain's
        // separators — the exact bug being fixed. CLDR writes it the Mexican
        // way, which is what their own device shows them.
        'es-419',
        'es-AR', // ARS
        'es-BO', // BOB
        'es-CL', // CLP
        'es-CO', // COP
        'es-DO', // DOP
        'es-ES', // EUR
        'es-GT', // GTQ
        'es-HN', // HNL
        'es-MX', // MXN
        'es-PE', // PEN
        'es-PY', // PYG
        'es-UY', // UYU
        'es-VE', // VES
        'fr-CA', // CAD
        'fr-FR', // EUR
        'it-IT', // EUR
        'ja-JP', // JPY
        'nl-NL', // EUR
        'pt-BR', // BRL
        'pt-PT', // EUR
        'sr-RS', // RSD
        'sv-SE', // SEK
        'th-TH', // THB
        'zh-CN', // CNY
        'zh-HK', // HKD
    ],

    /*
     * What each language formatted like before it had a region of its own, so
     * a reader whose browser names no region we know is left exactly where
     * they were rather than moved somewhere new.
     */
    'fallbacks' => [
        'en' => 'en-US',
        'es' => 'es-ES',
        'fr' => 'fr-FR',
    ],

    'default' => 'en-US',
];
