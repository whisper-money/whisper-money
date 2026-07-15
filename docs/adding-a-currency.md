# Adding a currency

Adding a currency is a config change, not a feature. In the common case it is a
single entry in `config/currencies.php` plus one translation string. Everything
else — validation, the Inertia props that feed the dropdowns, and conversion —
derives from that entry automatically.

Reference PRs: [#642 (NGN)](https://github.com/whisper-money/whisper-money/pull/642),
[#644 (GHS)](https://github.com/whisper-money/whisper-money/pull/644).

---

## The one required change

Add an entry to the `options` array in `config/currencies.php`:

```php
[
    'code' => 'NGN',            // ISO 4217 code, stored on users/accounts
    'name' => 'Nigerian Naira', // English name; the __() key for translations
    'allows_primary' => true,   // can be a user's primary/display currency
    'allows_account' => true,   // can be an individual account's currency
],
```

`allows_primary` / `allows_account` are independent. Assets you can hold but not
display everything in (e.g. `BTC`) are `allows_account => true`,
`allows_primary => false`.

That's the whole wiring. `App\Services\CurrencyOptions` reads this config and
feeds:

- **Validation** — `in:` rules in `ProfileUpdateRequest`, `StoreAccountRequest`,
  `UpdateAccountRequest` all pull from `CurrencyOptions`, so the new code is
  accepted the moment it's in config.
- **The UI dropdowns** — `HandleInertiaRequests` shares `primaryOptions()` /
  `accountOptions()` as Inertia props; the React selects render whatever is there.
- **Conversion** — `CurrencyConversionService` fetches
  `{date}/v1/currencies/{code}.min.json` from the `@fawazahmed0/currency-api`
  CDN and `ExchangeRateService` stores rates as JSON. Both lowercase the code and
  are currency-agnostic, so any code the provider covers just works. No code change.

---

## The translation (do this too)

`CurrencyOptions` runs the `name` through `__()` in PHP before handing it to the
frontend, so add the Spanish name to `lang/es.json`:

```json
"Nigerian Naira": "Naira Nigeriano",
```

Spanish (`es`) is the enforced locale. French (`lang/fr.json`) is optional and
warning-only — add it if you can (#644 did), skip it otherwise.

> ⚠️ `LocalizationTest` scans **TS/TSX source** for `__()` keys. Currency names
> are translated in PHP and never appear literally in the frontend, so a missing
> Spanish name is **not** auto-caught by CI. Add it by hand or the UI shows the
> English name.

---

## Optional: a custom symbol

`resources/js/utils/currency.ts` has a small `getCurrencySymbol` map for short
symbols (`$`, `€`, `₦`). It falls back to the currency code, and `formatCurrency`
uses `Intl.NumberFormat` which renders its own symbol regardless — so only add an
entry if you want a specific short glyph:

```ts
NGN: '₦',
```

`#644` (GHS) skipped this; `#642` (NGN) added it. Both are fine.

---

## Two things to verify before you add a code

1. **Use the current ISO 4217 code.** #644 was requested as `GHC` — the
   deprecated pre-2007 Ghanaian code. The provider still serves `ghc`, but at a
   rate scaled ×10 000 (Ghana redenominated: 1 GHS = 10 000 GHC), which would
   inflate every balance 10 000×. The correct modern code is `GHS`. Deprecated
   codes existing in the provider does not mean they're correct.

2. **Confirm the provider actually covers it, at the right rate.** Open
   `https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{code}.min.json`
   (lowercase) and check the code is present and the rate is sane
   (e.g. `EUR→NGN ≈ 1567`). If it's missing, conversion silently returns the
   unconverted amount (logged as a warning) — the currency is still selectable,
   it just won't convert.

---

## Test & checklist

- [ ] entry in `config/currencies.php` (`code`, `name`, `allows_primary`, `allows_account`)
- [ ] Spanish name in `lang/es.json` (French in `lang/fr.json` if you can)
- [ ] symbol in `resources/js/utils/currency.ts` (only if you want a custom glyph)
- [ ] provider covers the code at the correct rate (verified via the CDN URL)

```bash
php artisan test --compact tests/Feature/CurrencyConversionServiceTest.php \
                           tests/Feature/LocalizationTest.php
vendor/bin/pint --dirty
```
