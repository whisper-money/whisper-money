# Amounts and currencies

Every amount is an integer in the smallest unit of its own currency, next to the currency it is in.

{{TOC}}

## Integers in minor units

The API never sends or accepts decimals for money. An amount is a whole number
of the currency's minor unit, and the object it belongs to carries its
`currency` as an ISO 4217 code:

```json
{
    "amount": -1250,
    "currency": "EUR"
}
```

That is −12.50 €: 1,250 cents. Negative amounts are money going out, positive
amounts money coming in.

## Not every currency has cents

How many minor units make one major unit depends on the currency, so never
divide by 100 blindly. Read the `currency` first:

| Currency                      | Minor units per unit | `1250` means |
| ----------------------------- | -------------------- | ------------ |
| EUR, USD, GBP and most others | 100                  | 12.50        |
| JPY, COP, CLP, PYG, PKR       | 1                    | 1,250        |
| KWD                           | 1,000                | 1.250        |
| BTC                           | 100,000,000          | 0.0000125    |

When you send an amount, use the minor units of the currency of the account or
object it is for.

The one exception is the `conditions` of an automation rule: an amount compared
there is in major units and signed, so an expense of 12.50 is `-12.5`.

## Dates

Dates are ISO 8601 strings: `2026-01-15` for a calendar date, such as the day of
a transaction, and `2026-01-15T09:30:00+00:00` for a moment, such as when
something was created.
