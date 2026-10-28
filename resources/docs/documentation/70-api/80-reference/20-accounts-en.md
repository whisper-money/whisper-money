# Accounts

An account is a bookkeeping record of money held somewhere: a manual one you keep up to date yourself, or a bank-connected one the bank sync keeps up to date. Nothing here moves money; that happens at the bank.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

{{TOC}}

## List accounts

`GET /api/v1/accounts`

The accounts in a space, newest first, archived ones included. Every member of a space sees all of its accounts, whoever owns them.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `limit` | query | integer | No | How many objects to return, between 1 and 100. |
| `starting_after` | query | string (uuid) | No | An object id: return the page that follows it. Pass the last id of the previous page. |
| `ending_before` | query | string (uuid) | No | An object id: return the page that precedes it. Pass the first id of the next page. Cannot be combined with `starting_after`. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | array of Account |  |
| `data[].id` | string (uuid) | The account's id. |
| `data[].space_id` | string (uuid) or null | The id of the space the account is in. |
| `data[].name` | string | The account's name. |
| `data[].type` | "checking" \| "credit_card" \| "investment" \| "loan" \| "retirement" \| "real_estate" \| "savings" \| "others" | The account type. `checking`, `credit_card`, `savings` and `others` keep a transaction ledger; `investment`, `retirement`, `loan` and `real_estate` track a balance only. |
| `data[].currency` | string | The ISO 4217 currency the account is held in, which its amounts are in. |
| `data[].bank` | object or null | The bank the account is held at, if one is set: its id and name. |
| `data[].bank.id` | string |  |
| `data[].bank.name` | string |  |
| `data[].is_connected` | boolean | Whether the account is connected to a bank. A connected account's balances, currency and bank come from the sync: only its name, type and ownership can be changed, and it takes no manual balance. |
| `data[].owned` | boolean | Whether you own the account. Every member of its space reads it, but only its owner can edit it. |
| `data[].ownership_percentage` | integer | The owner's share of the account, 1 to 100. Below 100 only that slice counts in their figures. |
| `data[].ownership_applies_to_balance` | boolean | Whether the ownership share applies to the balance too, not only to transactions. |
| `data[].credit_card_detail` | object or null | `credit_card` only, otherwise `null`: the card's limit and statement dates, in minor units and ISO dates. Later cycles repeat from the dates at `statement_frequency`. `null` while none is set. |
| `data[].credit_card_detail.statement_closing_date` | string or null |  |
| `data[].credit_card_detail.payment_due_date` | string or null |  |
| `data[].credit_card_detail.statement_frequency` | "monthly" \| "weekly" |  |
| `data[].credit_card_detail.credit_limit` | integer or null |  |
| `data[].credit_card_statement` | object or null | `credit_card` only, otherwise `null`: an estimate of the card's next charge, computed from its statement dates and its transactions. It is not a transaction, and `next_payment.is_final` is false while the cycle is still open. `null` while the card has no statement dates. |
| `data[].credit_card_statement.next_payment` | object |  |
| `data[].credit_card_statement.next_payment.period_from` | string |  |
| `data[].credit_card_statement.next_payment.closing_date` | string |  |
| `data[].credit_card_statement.next_payment.due_date` | string |  |
| `data[].credit_card_statement.next_payment.amount` | integer |  |
| `data[].credit_card_statement.next_payment.is_final` | boolean |  |
| `data[].credit_card_statement.next_payment.paid` | integer |  |
| `data[].credit_card_statement.current_cycle` | object |  |
| `data[].credit_card_statement.current_cycle.period_from` | string |  |
| `data[].credit_card_statement.current_cycle.closing_date` | string |  |
| `data[].credit_card_statement.current_cycle.due_date` | string |  |
| `data[].credit_card_statement.current_cycle.amount` | integer |  |
| `data[].credit_card_statement.payments` | array of object |  |
| `data[].credit_card_statement.payments[].transaction_id` | string |  |
| `data[].credit_card_statement.payments[].date` | string |  |
| `data[].credit_card_statement.payments[].amount` | integer |  |
| `data[].credit_card_statement.payments[].period_from` | string |  |
| `data[].credit_card_statement.payments[].closing_date` | string |  |
| `data[].credit_card_statement.payments[].due_date` | string |  |
| `data[].credit_card_statement.previous_statement` | object |  |
| `data[].credit_card_statement.previous_statement.period_from` | string |  |
| `data[].credit_card_statement.previous_statement.closing_date` | string |  |
| `data[].credit_card_statement.previous_statement.due_date` | string |  |
| `data[].credit_card_statement.previous_statement.purchases` | integer |  |
| `data[].credit_card_statement.previous_statement.paid` | integer |  |
| `data[].credit_card_statement.previous_statement.pending` | integer |  |
| `data[].credit_card_statement.previous_statement.has_recorded_purchases` | boolean |  |
| `data[].credit_card_usage` | object or null | `credit_card` only, otherwise `null`: how much of the card is in use. `used` is what is still to be charged between `period_from` and today, less the payments made on those statements, never below 0. `available` is `limit` minus it, negative over the limit; both `limit` and `available` are `null` while no credit limit is set. |
| `data[].credit_card_usage.limit` | integer or null |  |
| `data[].credit_card_usage.used` | integer |  |
| `data[].credit_card_usage.available` | integer or null |  |
| `data[].credit_card_usage.period_from` | string |  |
| `data[].credit_card_usage.period_to` | string |  |
| `data[].archived_at` | string (date-time) or null | When the account was archived in the app, or `null`. An archived account keeps its history and still answers here. |
| `data[].created_at` | string (date-time) or null | When the account was created. |
| `has_more` | boolean | Whether more objects exist past this page, in the direction being walked. |

#### Example request

```bash
curl https://whisper.money/api/v1/accounts \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
            "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "name": "Main checking",
            "type": "checking",
            "currency": "EUR",
            "bank": {
                "id": "0199a3f1-5b2c-7e8d-9f0a-1b2c3d4e5f60",
                "name": "BBVA"
            },
            "is_connected": false,
            "owned": true,
            "ownership_percentage": 100,
            "ownership_applies_to_balance": true,
            "credit_card_detail": null,
            "credit_card_statement": null,
            "credit_card_usage": null,
            "archived_at": null,
            "created_at": "2026-01-15T09:30:00+00:00"
        }
    ],
    "has_more": true
}
```

#### Errors

| Status | When |
| --- | --- |
| `400` | A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`). |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`). |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Create an account

`POST /api/v1/accounts`

Opens a manual account of any type, optionally with today's balance. A loan or a property takes its own details, and given a `balance` too it gets the monthly balance history in between generated, the older part in the background. A bank-connected account cannot be created here: it is only born when you connect your bank in the Whisper Money app, so `banking_connection_id`, `external_account_id` and `is_connected` are refused.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | Yes | The account's name. |
| `type` | "checking" \| "credit_card" \| "investment" \| "loan" \| "retirement" \| "real_estate" \| "savings" \| "others" | Yes | The account type. `checking`, `credit_card`, `savings` and `others` keep a transaction ledger; `investment`, `retirement`, `loan` and `real_estate` track a balance only. |
| `currency_code` | "USD" \| "EUR" \| "GBP" \| "JPY" \| "CHF" \| "CZK" \| "CAD" \| "AUD" \| "NZD" \| "CNY" \| "INR" \| "PKR" \| "MXN" \| "ARS" \| "BOB" \| "CLP" \| "PYG" \| "PEN" \| "UYU" \| "VES" \| "BRL" \| "COP" \| "DOP" \| "SAR" \| "KWD" \| "BTC" \| "RSD" \| "NGN" \| "GHS" \| "SEK" \| "GTQ" \| "HNL" \| "HKD" \| "THB" \| "DKK" \| "SGD" | Yes | The ISO 4217 currency the account is held in. Your first account also sets the currency your whole account is reported in, so it must be one of the primary currencies. |
| `bank_id` | string or null | No | The id of the bank the account is held at, for its name and logo. It does not connect anything. |
| `balance` | integer or null | No | The balance today, in minor units. On a loan or a property it is also where the generated balance history ends. Refused on a credit card, which takes a `credit_limit` instead. |
| `linked_real_estate_account_id` | string (uuid) or null | No | `loan` only: id of the `real_estate` account this loan is the mortgage of. The property must have its details set and not be linked to another loan yet. |
| `property_type` | "residential" \| "commercial" \| "land" \| "vacation" \| "other" | No | `real_estate` only: what kind of property it is. Required when the property's details are first created. |
| `address` | string or null | No | `real_estate` only: the property's address. |
| `purchase_price` | integer or null | No | `real_estate` only: what it was bought for, in minor units. With `purchase_date` and a `balance`, the value history in between is filled in. |
| `purchase_date` | string (date) or null | No | `real_estate` only: when it was bought. Not in the future, not before 1900. |
| `area_value` | number or null | No | `real_estate` only: floor area, in `area_unit`. |
| `area_unit` | "sqm" \| "sqft" \| "acres" \| "hectares" \| null | No | `real_estate` only: the unit of `area_value`. |
| `revaluation_percentage` | number or null | No | `real_estate` only: expected yearly revaluation, as a percentage. |
| `notes` | string or null | No | `real_estate` only: free-text notes on the property. |
| `linked_loan_account_id` | string (uuid) or null | No | `real_estate` only: id of one of your `loan` accounts that is this property's mortgage. |
| `annual_interest_rate` | number or null | No | `loan` only: yearly interest rate, as a percentage. |
| `loan_term_months` | integer or null | No | `loan` only: the term, in months. |
| `original_amount` | integer or null | No | `loan` only: the amount originally borrowed, in minor units. A loan's details are created once `annual_interest_rate`, `loan_term_months` and `original_amount` are all known. |
| `loan_start_date` | string (date) or null | No | `loan` only: when the loan started. Defaults to today. |
| `statement_closing_date` | string (date) or null | No | `credit_card` only: a statement closing date. Later cycles repeat from it at `statement_frequency`. Send it with `payment_due_date`; both `null` clears them. |
| `payment_due_date` | string (date) or null | No | `credit_card` only: when that statement is charged. On or after the closing date, at most 45 days later on a monthly card and 7 on a weekly one. |
| `statement_frequency` | "monthly" \| "weekly" \| null | No | `credit_card` only: how often a statement closes. Left out, the stored one stays (monthly on a new card). |
| `credit_limit` | integer or null | No | `credit_card` only: the card's credit limit, in minor units. `null` clears it. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Account |  |
| `data.id` | string (uuid) | The account's id. |
| `data.space_id` | string (uuid) or null | The id of the space the account is in. |
| `data.name` | string | The account's name. |
| `data.type` | "checking" \| "credit_card" \| "investment" \| "loan" \| "retirement" \| "real_estate" \| "savings" \| "others" | The account type. `checking`, `credit_card`, `savings` and `others` keep a transaction ledger; `investment`, `retirement`, `loan` and `real_estate` track a balance only. |
| `data.currency` | string | The ISO 4217 currency the account is held in, which its amounts are in. |
| `data.bank` | object or null | The bank the account is held at, if one is set: its id and name. |
| `data.bank.id` | string |  |
| `data.bank.name` | string |  |
| `data.is_connected` | boolean | Whether the account is connected to a bank. A connected account's balances, currency and bank come from the sync: only its name, type and ownership can be changed, and it takes no manual balance. |
| `data.owned` | boolean | Whether you own the account. Every member of its space reads it, but only its owner can edit it. |
| `data.ownership_percentage` | integer | The owner's share of the account, 1 to 100. Below 100 only that slice counts in their figures. |
| `data.ownership_applies_to_balance` | boolean | Whether the ownership share applies to the balance too, not only to transactions. |
| `data.credit_card_detail` | object or null | `credit_card` only, otherwise `null`: the card's limit and statement dates, in minor units and ISO dates. Later cycles repeat from the dates at `statement_frequency`. `null` while none is set. |
| `data.credit_card_detail.statement_closing_date` | string or null |  |
| `data.credit_card_detail.payment_due_date` | string or null |  |
| `data.credit_card_detail.statement_frequency` | "monthly" \| "weekly" |  |
| `data.credit_card_detail.credit_limit` | integer or null |  |
| `data.credit_card_statement` | object or null | `credit_card` only, otherwise `null`: an estimate of the card's next charge, computed from its statement dates and its transactions. It is not a transaction, and `next_payment.is_final` is false while the cycle is still open. `null` while the card has no statement dates. |
| `data.credit_card_statement.next_payment` | object |  |
| `data.credit_card_statement.next_payment.period_from` | string |  |
| `data.credit_card_statement.next_payment.closing_date` | string |  |
| `data.credit_card_statement.next_payment.due_date` | string |  |
| `data.credit_card_statement.next_payment.amount` | integer |  |
| `data.credit_card_statement.next_payment.is_final` | boolean |  |
| `data.credit_card_statement.next_payment.paid` | integer |  |
| `data.credit_card_statement.current_cycle` | object |  |
| `data.credit_card_statement.current_cycle.period_from` | string |  |
| `data.credit_card_statement.current_cycle.closing_date` | string |  |
| `data.credit_card_statement.current_cycle.due_date` | string |  |
| `data.credit_card_statement.current_cycle.amount` | integer |  |
| `data.credit_card_statement.payments` | array of object |  |
| `data.credit_card_statement.payments[].transaction_id` | string |  |
| `data.credit_card_statement.payments[].date` | string |  |
| `data.credit_card_statement.payments[].amount` | integer |  |
| `data.credit_card_statement.payments[].period_from` | string |  |
| `data.credit_card_statement.payments[].closing_date` | string |  |
| `data.credit_card_statement.payments[].due_date` | string |  |
| `data.credit_card_statement.previous_statement` | object |  |
| `data.credit_card_statement.previous_statement.period_from` | string |  |
| `data.credit_card_statement.previous_statement.closing_date` | string |  |
| `data.credit_card_statement.previous_statement.due_date` | string |  |
| `data.credit_card_statement.previous_statement.purchases` | integer |  |
| `data.credit_card_statement.previous_statement.paid` | integer |  |
| `data.credit_card_statement.previous_statement.pending` | integer |  |
| `data.credit_card_statement.previous_statement.has_recorded_purchases` | boolean |  |
| `data.credit_card_usage` | object or null | `credit_card` only, otherwise `null`: how much of the card is in use. `used` is what is still to be charged between `period_from` and today, less the payments made on those statements, never below 0. `available` is `limit` minus it, negative over the limit; both `limit` and `available` are `null` while no credit limit is set. |
| `data.credit_card_usage.limit` | integer or null |  |
| `data.credit_card_usage.used` | integer |  |
| `data.credit_card_usage.available` | integer or null |  |
| `data.credit_card_usage.period_from` | string |  |
| `data.credit_card_usage.period_to` | string |  |
| `data.archived_at` | string (date-time) or null | When the account was archived in the app, or `null`. An archived account keeps its history and still answers here. |
| `data.created_at` | string (date-time) or null | When the account was created. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/accounts \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Main checking",
    "type": "checking",
    "currency_code": "EUR",
    "balance": 125000
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Main checking",
        "type": "checking",
        "currency": "EUR",
        "bank": {
            "id": "0199a3f1-5b2c-7e8d-9f0a-1b2c3d4e5f60",
            "name": "BBVA"
        },
        "is_connected": false,
        "owned": true,
        "ownership_percentage": 100,
        "ownership_applies_to_balance": true,
        "credit_card_detail": null,
        "credit_card_statement": null,
        "credit_card_usage": null,
        "archived_at": null,
        "created_at": "2026-01-15T09:30:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `400` | A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`). |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`). |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Retrieve an account

`GET /api/v1/accounts/{account}`

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `account` | path | string (uuid) | Yes | The account's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Account |  |
| `data.id` | string (uuid) | The account's id. |
| `data.space_id` | string (uuid) or null | The id of the space the account is in. |
| `data.name` | string | The account's name. |
| `data.type` | "checking" \| "credit_card" \| "investment" \| "loan" \| "retirement" \| "real_estate" \| "savings" \| "others" | The account type. `checking`, `credit_card`, `savings` and `others` keep a transaction ledger; `investment`, `retirement`, `loan` and `real_estate` track a balance only. |
| `data.currency` | string | The ISO 4217 currency the account is held in, which its amounts are in. |
| `data.bank` | object or null | The bank the account is held at, if one is set: its id and name. |
| `data.bank.id` | string |  |
| `data.bank.name` | string |  |
| `data.is_connected` | boolean | Whether the account is connected to a bank. A connected account's balances, currency and bank come from the sync: only its name, type and ownership can be changed, and it takes no manual balance. |
| `data.owned` | boolean | Whether you own the account. Every member of its space reads it, but only its owner can edit it. |
| `data.ownership_percentage` | integer | The owner's share of the account, 1 to 100. Below 100 only that slice counts in their figures. |
| `data.ownership_applies_to_balance` | boolean | Whether the ownership share applies to the balance too, not only to transactions. |
| `data.credit_card_detail` | object or null | `credit_card` only, otherwise `null`: the card's limit and statement dates, in minor units and ISO dates. Later cycles repeat from the dates at `statement_frequency`. `null` while none is set. |
| `data.credit_card_detail.statement_closing_date` | string or null |  |
| `data.credit_card_detail.payment_due_date` | string or null |  |
| `data.credit_card_detail.statement_frequency` | "monthly" \| "weekly" |  |
| `data.credit_card_detail.credit_limit` | integer or null |  |
| `data.credit_card_statement` | object or null | `credit_card` only, otherwise `null`: an estimate of the card's next charge, computed from its statement dates and its transactions. It is not a transaction, and `next_payment.is_final` is false while the cycle is still open. `null` while the card has no statement dates. |
| `data.credit_card_statement.next_payment` | object |  |
| `data.credit_card_statement.next_payment.period_from` | string |  |
| `data.credit_card_statement.next_payment.closing_date` | string |  |
| `data.credit_card_statement.next_payment.due_date` | string |  |
| `data.credit_card_statement.next_payment.amount` | integer |  |
| `data.credit_card_statement.next_payment.is_final` | boolean |  |
| `data.credit_card_statement.next_payment.paid` | integer |  |
| `data.credit_card_statement.current_cycle` | object |  |
| `data.credit_card_statement.current_cycle.period_from` | string |  |
| `data.credit_card_statement.current_cycle.closing_date` | string |  |
| `data.credit_card_statement.current_cycle.due_date` | string |  |
| `data.credit_card_statement.current_cycle.amount` | integer |  |
| `data.credit_card_statement.payments` | array of object |  |
| `data.credit_card_statement.payments[].transaction_id` | string |  |
| `data.credit_card_statement.payments[].date` | string |  |
| `data.credit_card_statement.payments[].amount` | integer |  |
| `data.credit_card_statement.payments[].period_from` | string |  |
| `data.credit_card_statement.payments[].closing_date` | string |  |
| `data.credit_card_statement.payments[].due_date` | string |  |
| `data.credit_card_statement.previous_statement` | object |  |
| `data.credit_card_statement.previous_statement.period_from` | string |  |
| `data.credit_card_statement.previous_statement.closing_date` | string |  |
| `data.credit_card_statement.previous_statement.due_date` | string |  |
| `data.credit_card_statement.previous_statement.purchases` | integer |  |
| `data.credit_card_statement.previous_statement.paid` | integer |  |
| `data.credit_card_statement.previous_statement.pending` | integer |  |
| `data.credit_card_statement.previous_statement.has_recorded_purchases` | boolean |  |
| `data.credit_card_usage` | object or null | `credit_card` only, otherwise `null`: how much of the card is in use. `used` is what is still to be charged between `period_from` and today, less the payments made on those statements, never below 0. `available` is `limit` minus it, negative over the limit; both `limit` and `available` are `null` while no credit limit is set. |
| `data.credit_card_usage.limit` | integer or null |  |
| `data.credit_card_usage.used` | integer |  |
| `data.credit_card_usage.available` | integer or null |  |
| `data.credit_card_usage.period_from` | string |  |
| `data.credit_card_usage.period_to` | string |  |
| `data.archived_at` | string (date-time) or null | When the account was archived in the app, or `null`. An archived account keeps its history and still answers here. |
| `data.created_at` | string (date-time) or null | When the account was created. |

#### Example request

```bash
curl https://whisper.money/api/v1/accounts/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Main checking",
        "type": "checking",
        "currency": "EUR",
        "bank": {
            "id": "0199a3f1-5b2c-7e8d-9f0a-1b2c3d4e5f60",
            "name": "BBVA"
        },
        "is_connected": false,
        "owned": true,
        "ownership_percentage": 100,
        "ownership_applies_to_balance": true,
        "credit_card_detail": null,
        "credit_card_statement": null,
        "credit_card_usage": null,
        "archived_at": null,
        "created_at": "2026-01-15T09:30:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Update an account

`PATCH /api/v1/accounts/{account}`

Changes only the fields you send. On a manual account that is all of them; on a bank-connected one only the name, the type (among `checking`, `credit_card`, `savings` and `others`) and the ownership fields, since its currency and bank come from the bank. Only the account's owner can edit it, not the other members of its space. A loan with no details yet needs `annual_interest_rate`, `loan_term_months` and `original_amount` together; sending only some of them is a `422` and nothing in the request is saved. Changing a loan or a property to another type keeps its details and any mortgage link on file but stops using them. Archiving, hiding and deleting an account happen in the app.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `account` | path | string (uuid) | Yes | The account's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | No | The account's new name. The sync never rewrites it, so a bank-connected account can be renamed too. |
| `type` | "checking" \| "credit_card" \| "investment" \| "loan" \| "retirement" \| "real_estate" \| "savings" \| "others" | No | The new account type. On a bank-connected account only `checking`, `credit_card`, `savings` and `others` are possible: the rest keep no transaction ledger for the sync to write into. |
| `currency_code` | "USD" \| "EUR" \| "GBP" \| "JPY" \| "CHF" \| "CZK" \| "CAD" \| "AUD" \| "NZD" \| "CNY" \| "INR" \| "PKR" \| "MXN" \| "ARS" \| "BOB" \| "CLP" \| "PYG" \| "PEN" \| "UYU" \| "VES" \| "BRL" \| "COP" \| "DOP" \| "SAR" \| "KWD" \| "BTC" \| "RSD" \| "NGN" \| "GHS" \| "SEK" \| "GTQ" \| "HNL" \| "HKD" \| "THB" \| "DKK" \| "SGD" | No | The new ISO 4217 currency. Refused on a bank-connected account, whose currency comes from the bank. |
| `bank_id` | string or null | No | The new bank id, or `null` to clear it. Refused on a bank-connected account, whose bank comes from the connection. |
| `ownership_percentage` | integer | No | Your share of the account, 1 to 100. Below 100 only that slice counts in your figures, and the budgets that already counted the account are reweighed. |
| `ownership_applies_to_balance` | boolean | No | Whether the ownership share applies to the balance too, not only to transactions. |
| `property_type` | "residential" \| "commercial" \| "land" \| "vacation" \| "other" | No | `real_estate` only: what kind of property it is. Required when the property's details are first created. |
| `address` | string or null | No | `real_estate` only: the property's address. |
| `purchase_price` | integer or null | No | `real_estate` only: what it was bought for, in minor units. With `purchase_date` and a `balance`, the value history in between is filled in. |
| `purchase_date` | string (date) or null | No | `real_estate` only: when it was bought. Not in the future, not before 1900. |
| `area_value` | number or null | No | `real_estate` only: floor area, in `area_unit`. |
| `area_unit` | "sqm" \| "sqft" \| "acres" \| "hectares" \| null | No | `real_estate` only: the unit of `area_value`. |
| `revaluation_percentage` | number or null | No | `real_estate` only: expected yearly revaluation, as a percentage. |
| `notes` | string or null | No | `real_estate` only: free-text notes on the property. |
| `linked_loan_account_id` | string (uuid) or null | No | `real_estate` only: id of one of your `loan` accounts that is this property's mortgage. |
| `annual_interest_rate` | number or null | No | `loan` only: yearly interest rate, as a percentage. |
| `loan_term_months` | integer or null | No | `loan` only: the term, in months. |
| `original_amount` | integer or null | No | `loan` only: the amount originally borrowed, in minor units. A loan's details are created once `annual_interest_rate`, `loan_term_months` and `original_amount` are all known. |
| `loan_start_date` | string (date) or null | No | `loan` only: when the loan started. Defaults to today. |
| `statement_closing_date` | string (date) or null | No | `credit_card` only: a statement closing date. Later cycles repeat from it at `statement_frequency`. Send it with `payment_due_date`; both `null` clears them. |
| `payment_due_date` | string (date) or null | No | `credit_card` only: when that statement is charged. On or after the closing date, at most 45 days later on a monthly card and 7 on a weekly one. |
| `statement_frequency` | "monthly" \| "weekly" \| null | No | `credit_card` only: how often a statement closes. Left out, the stored one stays (monthly on a new card). |
| `credit_limit` | integer or null | No | `credit_card` only: the card's credit limit, in minor units. `null` clears it. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Account |  |
| `data.id` | string (uuid) | The account's id. |
| `data.space_id` | string (uuid) or null | The id of the space the account is in. |
| `data.name` | string | The account's name. |
| `data.type` | "checking" \| "credit_card" \| "investment" \| "loan" \| "retirement" \| "real_estate" \| "savings" \| "others" | The account type. `checking`, `credit_card`, `savings` and `others` keep a transaction ledger; `investment`, `retirement`, `loan` and `real_estate` track a balance only. |
| `data.currency` | string | The ISO 4217 currency the account is held in, which its amounts are in. |
| `data.bank` | object or null | The bank the account is held at, if one is set: its id and name. |
| `data.bank.id` | string |  |
| `data.bank.name` | string |  |
| `data.is_connected` | boolean | Whether the account is connected to a bank. A connected account's balances, currency and bank come from the sync: only its name, type and ownership can be changed, and it takes no manual balance. |
| `data.owned` | boolean | Whether you own the account. Every member of its space reads it, but only its owner can edit it. |
| `data.ownership_percentage` | integer | The owner's share of the account, 1 to 100. Below 100 only that slice counts in their figures. |
| `data.ownership_applies_to_balance` | boolean | Whether the ownership share applies to the balance too, not only to transactions. |
| `data.credit_card_detail` | object or null | `credit_card` only, otherwise `null`: the card's limit and statement dates, in minor units and ISO dates. Later cycles repeat from the dates at `statement_frequency`. `null` while none is set. |
| `data.credit_card_detail.statement_closing_date` | string or null |  |
| `data.credit_card_detail.payment_due_date` | string or null |  |
| `data.credit_card_detail.statement_frequency` | "monthly" \| "weekly" |  |
| `data.credit_card_detail.credit_limit` | integer or null |  |
| `data.credit_card_statement` | object or null | `credit_card` only, otherwise `null`: an estimate of the card's next charge, computed from its statement dates and its transactions. It is not a transaction, and `next_payment.is_final` is false while the cycle is still open. `null` while the card has no statement dates. |
| `data.credit_card_statement.next_payment` | object |  |
| `data.credit_card_statement.next_payment.period_from` | string |  |
| `data.credit_card_statement.next_payment.closing_date` | string |  |
| `data.credit_card_statement.next_payment.due_date` | string |  |
| `data.credit_card_statement.next_payment.amount` | integer |  |
| `data.credit_card_statement.next_payment.is_final` | boolean |  |
| `data.credit_card_statement.next_payment.paid` | integer |  |
| `data.credit_card_statement.current_cycle` | object |  |
| `data.credit_card_statement.current_cycle.period_from` | string |  |
| `data.credit_card_statement.current_cycle.closing_date` | string |  |
| `data.credit_card_statement.current_cycle.due_date` | string |  |
| `data.credit_card_statement.current_cycle.amount` | integer |  |
| `data.credit_card_statement.payments` | array of object |  |
| `data.credit_card_statement.payments[].transaction_id` | string |  |
| `data.credit_card_statement.payments[].date` | string |  |
| `data.credit_card_statement.payments[].amount` | integer |  |
| `data.credit_card_statement.payments[].period_from` | string |  |
| `data.credit_card_statement.payments[].closing_date` | string |  |
| `data.credit_card_statement.payments[].due_date` | string |  |
| `data.credit_card_statement.previous_statement` | object |  |
| `data.credit_card_statement.previous_statement.period_from` | string |  |
| `data.credit_card_statement.previous_statement.closing_date` | string |  |
| `data.credit_card_statement.previous_statement.due_date` | string |  |
| `data.credit_card_statement.previous_statement.purchases` | integer |  |
| `data.credit_card_statement.previous_statement.paid` | integer |  |
| `data.credit_card_statement.previous_statement.pending` | integer |  |
| `data.credit_card_statement.previous_statement.has_recorded_purchases` | boolean |  |
| `data.credit_card_usage` | object or null | `credit_card` only, otherwise `null`: how much of the card is in use. `used` is what is still to be charged between `period_from` and today, less the payments made on those statements, never below 0. `available` is `limit` minus it, negative over the limit; both `limit` and `available` are `null` while no credit limit is set. |
| `data.credit_card_usage.limit` | integer or null |  |
| `data.credit_card_usage.used` | integer |  |
| `data.credit_card_usage.available` | integer or null |  |
| `data.credit_card_usage.period_from` | string |  |
| `data.credit_card_usage.period_to` | string |  |
| `data.archived_at` | string (date-time) or null | When the account was archived in the app, or `null`. An archived account keeps its history and still answers here. |
| `data.created_at` | string (date-time) or null | When the account was created. |

#### Example request

```bash
curl -X PATCH https://whisper.money/api/v1/accounts/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Joint checking",
    "ownership_percentage": 50
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Main checking",
        "type": "checking",
        "currency": "EUR",
        "bank": {
            "id": "0199a3f1-5b2c-7e8d-9f0a-1b2c3d4e5f60",
            "name": "BBVA"
        },
        "is_connected": false,
        "owned": true,
        "ownership_percentage": 100,
        "ownership_applies_to_balance": true,
        "credit_card_detail": null,
        "credit_card_statement": null,
        "credit_card_usage": null,
        "archived_at": null,
        "created_at": "2026-01-15T09:30:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |
