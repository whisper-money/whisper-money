# Transactions

A transaction is money that moved in or out of an account: synced from the bank, imported from a file or recorded by hand. Whisper Money only keeps the books: recording a transaction moves no money. Amounts are signed, in the minor units of the transaction's own currency: negative for an expense, positive for income.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

{{TOC}}

## List transactions

`GET /api/v1/transactions`

Your transactions in a space, the most recent day first, narrowed by any of the filters. As in the app, another member's transactions are not listed. A transaction that was split is not listed: its parts are, each with the same `split_parent_id`. To find recurring charges, such as subscriptions, search them and group the results by merchant and cadence.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `query` | query | string | No | Free text matched against the description and the creditor and debtor names. |
| `account_id` | query | string (uuid) | No | Only the transactions of this account. |
| `category_id` | query | string (uuid) | No | Only the transactions in this category. |
| `label_ids[]` | query | array of string (uuid) | No | Only the transactions tagged with any of these labels. |
| `from` | query | string (date) | No | The earliest transaction date, inclusive. |
| `to` | query | string (date) | No | The latest transaction date, inclusive. |
| `min_amount` | query | integer | No | The smallest signed amount, in minor units. `-5000` keeps all income and the expenses of up to 5000 minor units. |
| `max_amount` | query | integer | No | The largest signed amount, in minor units. `0` keeps expenses only. |
| `limit` | query | integer | No | How many objects to return, between 1 and 100. |
| `starting_after` | query | string (uuid) | No | An object id: return the page that follows it. Pass the last id of the previous page. |
| `ending_before` | query | string (uuid) | No | An object id: return the page that precedes it. Pass the first id of the next page. Cannot be combined with `starting_after`. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | array of Transaction |  |
| `data[].id` | string (uuid) | The transaction's id. |
| `data[].space_id` | string (uuid) or null | The id of the space the transaction is in, its account's. |
| `data[].account` | object | The account the transaction is on: its id and name. |
| `data[].account.id` | string |  |
| `data[].account.name` | string |  |
| `data[].transaction_date` | string (date) | The day the transaction happened. |
| `data[].description` | string | What the transaction was for. |
| `data[].amount` | integer | The signed amount, in the minor units of `currency`: negative for an expense, positive for income. |
| `data[].currency` | string | The ISO 4217 currency the amount is in. |
| `data[].category` | object or null | The category, if one is set: its id and name. |
| `data[].category.id` | string |  |
| `data[].category.name` | string |  |
| `data[].category_source` | "manual" \| "rule" \| "ai" \| "bank" \| null | Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none. |
| `data[].labels` | array of object | The labels the transaction is tagged with: their ids and names. |
| `data[].labels[].id` | string |  |
| `data[].labels[].name` | string |  |
| `data[].source` | "manually_created" \| "imported" \| "enablebanking" \| "wise" | Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, currency, account or counterparty names; notes and the category can be changed on any, the date on any but one part of a split, and any but one part of a split can be deleted. |
| `data[].creditor_name` | string or null | Who was paid, or `null`. |
| `data[].debtor_name` | string or null | Who paid, or `null`. |
| `data[].notes` | string or null | Free-text notes, or `null`. |
| `data[].split_parent_id` | string (uuid) or null | Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own. |
| `data[].created_at` | string (date-time) or null | When the transaction was recorded in Whisper Money. |
| `has_more` | boolean | Whether more objects exist past this page, in the direction being walked. |

#### Example request

```bash
curl https://whisper.money/api/v1/transactions \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
            "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "account": {
                "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
                "name": "Main checking"
            },
            "transaction_date": "2026-01-31",
            "description": "Groceries at the market",
            "amount": -4250,
            "currency": "EUR",
            "category": {
                "id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f",
                "name": "Groceries"
            },
            "category_source": "manual",
            "labels": [
                {
                    "id": "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a",
                    "name": "Holidays"
                }
            ],
            "source": "manually_created",
            "creditor_name": "Fresh Market",
            "debtor_name": null,
            "notes": null,
            "split_parent_id": null,
            "created_at": "2026-01-31T18:12:00+00:00"
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
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Create a transaction

`POST /api/v1/transactions`

Records a transaction by hand on one of your accounts in the space, bank-connected ones included: a sync never removes it. Nothing deduplicates it against what the bank sends, so add only what the bank will not sync, such as a cash payment or a transfer you made elsewhere. The response adds `balance_updated`, whether `update_balance` moved the account's balance snapshots.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `account_id` | string (uuid) | Yes | The id of the account the transaction is on: one of your accounts in the space, bank-connected ones included, since a sync never removes a transaction you added by hand. |
| `description` | string | Yes | What the transaction was for. |
| `amount` | integer | Yes | The signed amount, in the minor units of `currency_code`: negative for an expense, positive for income. |
| `transaction_date` | string (date) | Yes | The day the transaction happened. |
| `currency_code` | "USD" \| "EUR" \| "GBP" \| "JPY" \| "CHF" \| "CZK" \| "CAD" \| "AUD" \| "NZD" \| "CNY" \| "INR" \| "PKR" \| "MXN" \| "ARS" \| "BOB" \| "CLP" \| "PYG" \| "PEN" \| "UYU" \| "VES" \| "BRL" \| "COP" \| "DOP" \| "SAR" \| "KWD" \| "BTC" \| "RSD" \| "NGN" \| "GHS" \| "SEK" \| "GTQ" \| "HNL" \| "HKD" \| "THB" \| "DKK" \| "SGD" | No | The ISO 4217 currency the amount is in. Defaults to the account's. |
| `category_id` | string (uuid) or null | No | The id of the category, or `null` for none. Setting it marks the category as assigned by you, so the automatic categorization leaves it alone. |
| `creditor_name` | string or null | No | Who was paid, or `null`. |
| `debtor_name` | string or null | No | Who paid, or `null`. |
| `notes` | string or null | No | Free-text notes, or `null`. |
| `update_balance` | boolean | No | Whether to move the account's balance snapshots by what this write changes. Has no effect on a bank-connected account, whose balances come from the bank, or on a credit card, which has a credit limit instead of a balance; `balance_updated` in the response says whether they moved. Defaults to `false`. |
| `label_ids` | array of string (uuid) | No | The ids of the labels to tag the transaction with. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Transaction |  |
| `data.id` | string (uuid) | The transaction's id. |
| `data.space_id` | string (uuid) or null | The id of the space the transaction is in, its account's. |
| `data.account` | object | The account the transaction is on: its id and name. |
| `data.account.id` | string |  |
| `data.account.name` | string |  |
| `data.transaction_date` | string (date) | The day the transaction happened. |
| `data.description` | string | What the transaction was for. |
| `data.amount` | integer | The signed amount, in the minor units of `currency`: negative for an expense, positive for income. |
| `data.currency` | string | The ISO 4217 currency the amount is in. |
| `data.category` | object or null | The category, if one is set: its id and name. |
| `data.category.id` | string |  |
| `data.category.name` | string |  |
| `data.category_source` | "manual" \| "rule" \| "ai" \| "bank" \| null | Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none. |
| `data.labels` | array of object | The labels the transaction is tagged with: their ids and names. |
| `data.labels[].id` | string |  |
| `data.labels[].name` | string |  |
| `data.source` | "manually_created" \| "imported" \| "enablebanking" \| "wise" | Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, currency, account or counterparty names; notes and the category can be changed on any, the date on any but one part of a split, and any but one part of a split can be deleted. |
| `data.creditor_name` | string or null | Who was paid, or `null`. |
| `data.debtor_name` | string or null | Who paid, or `null`. |
| `data.notes` | string or null | Free-text notes, or `null`. |
| `data.split_parent_id` | string (uuid) or null | Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own. |
| `data.created_at` | string (date-time) or null | When the transaction was recorded in Whisper Money. |
| `balance_updated` | boolean | Whether `update_balance` moved the account's balance snapshots. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/transactions \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "account_id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
    "description": "Groceries at the market",
    "amount": -4250,
    "transaction_date": "2026-01-31",
    "currency_code": "EUR",
    "creditor_name": "Fresh Market"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "account": {
            "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
            "name": "Main checking"
        },
        "transaction_date": "2026-01-31",
        "description": "Groceries at the market",
        "amount": -4250,
        "currency": "EUR",
        "category": {
            "id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f",
            "name": "Groceries"
        },
        "category_source": "manual",
        "labels": [
            {
                "id": "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a",
                "name": "Holidays"
            }
        ],
        "source": "manually_created",
        "creditor_name": "Fresh Market",
        "debtor_name": null,
        "notes": null,
        "split_parent_id": null,
        "created_at": "2026-01-31T18:12:00+00:00"
    },
    "balance_updated": true
}
```

#### Errors

| Status | When |
| --- | --- |
| `400` | A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`). |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Retrieve a transaction

`GET /api/v1/transactions/{transaction}`

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `transaction` | path | string (uuid) | Yes | The transaction's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Transaction |  |
| `data.id` | string (uuid) | The transaction's id. |
| `data.space_id` | string (uuid) or null | The id of the space the transaction is in, its account's. |
| `data.account` | object | The account the transaction is on: its id and name. |
| `data.account.id` | string |  |
| `data.account.name` | string |  |
| `data.transaction_date` | string (date) | The day the transaction happened. |
| `data.description` | string | What the transaction was for. |
| `data.amount` | integer | The signed amount, in the minor units of `currency`: negative for an expense, positive for income. |
| `data.currency` | string | The ISO 4217 currency the amount is in. |
| `data.category` | object or null | The category, if one is set: its id and name. |
| `data.category.id` | string |  |
| `data.category.name` | string |  |
| `data.category_source` | "manual" \| "rule" \| "ai" \| "bank" \| null | Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none. |
| `data.labels` | array of object | The labels the transaction is tagged with: their ids and names. |
| `data.labels[].id` | string |  |
| `data.labels[].name` | string |  |
| `data.source` | "manually_created" \| "imported" \| "enablebanking" \| "wise" | Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, currency, account or counterparty names; notes and the category can be changed on any, the date on any but one part of a split, and any but one part of a split can be deleted. |
| `data.creditor_name` | string or null | Who was paid, or `null`. |
| `data.debtor_name` | string or null | Who paid, or `null`. |
| `data.notes` | string or null | Free-text notes, or `null`. |
| `data.split_parent_id` | string (uuid) or null | Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own. |
| `data.created_at` | string (date-time) or null | When the transaction was recorded in Whisper Money. |

#### Example request

```bash
curl https://whisper.money/api/v1/transactions/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "account": {
            "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
            "name": "Main checking"
        },
        "transaction_date": "2026-01-31",
        "description": "Groceries at the market",
        "amount": -4250,
        "currency": "EUR",
        "category": {
            "id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f",
            "name": "Groceries"
        },
        "category_source": "manual",
        "labels": [
            {
                "id": "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a",
                "name": "Holidays"
            }
        ],
        "source": "manually_created",
        "creditor_name": "Fresh Market",
        "debtor_name": null,
        "notes": null,
        "split_parent_id": null,
        "created_at": "2026-01-31T18:12:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Update a transaction

`PATCH /api/v1/transactions/{transaction}`

Changes only the fields you send, and also sets or clears the category. The labels are not changed here. The notes and the category can be changed on any transaction, and the date on any but one part of a split. The rest (description, amount, currency, account and counterparty names) only on one you recorded by hand that is not one part of a split: on any other, sending them is a `422` and nothing is saved. The response adds `balance_updated`, whether `update_balance` moved the account's balance snapshots.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `transaction` | path | string (uuid) | Yes | The transaction's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `account_id` | string (uuid) | No | The id of the account the transaction is on: one of your accounts in the space, bank-connected ones included, since a sync never removes a transaction you added by hand. |
| `description` | string | No | What the transaction was for. |
| `amount` | integer | No | The signed amount, in the minor units of `currency_code`: negative for an expense, positive for income. |
| `transaction_date` | string (date) | No | The day the transaction happened. |
| `currency_code` | "USD" \| "EUR" \| "GBP" \| "JPY" \| "CHF" \| "CZK" \| "CAD" \| "AUD" \| "NZD" \| "CNY" \| "INR" \| "PKR" \| "MXN" \| "ARS" \| "BOB" \| "CLP" \| "PYG" \| "PEN" \| "UYU" \| "VES" \| "BRL" \| "COP" \| "DOP" \| "SAR" \| "KWD" \| "BTC" \| "RSD" \| "NGN" \| "GHS" \| "SEK" \| "GTQ" \| "HNL" \| "HKD" \| "THB" \| "DKK" \| "SGD" | No | The ISO 4217 currency the amount is in. Defaults to the account's. |
| `category_id` | string (uuid) or null | No | The id of the category, or `null` for none. Setting it marks the category as assigned by you, so the automatic categorization leaves it alone. |
| `creditor_name` | string or null | No | Who was paid, or `null`. |
| `debtor_name` | string or null | No | Who paid, or `null`. |
| `notes` | string or null | No | Free-text notes, or `null`. |
| `update_balance` | boolean | No | Whether to move the account's balance snapshots by what this write changes. Has no effect on a bank-connected account, whose balances come from the bank, or on a credit card, which has a credit limit instead of a balance; `balance_updated` in the response says whether they moved. Defaults to `false`. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Transaction |  |
| `data.id` | string (uuid) | The transaction's id. |
| `data.space_id` | string (uuid) or null | The id of the space the transaction is in, its account's. |
| `data.account` | object | The account the transaction is on: its id and name. |
| `data.account.id` | string |  |
| `data.account.name` | string |  |
| `data.transaction_date` | string (date) | The day the transaction happened. |
| `data.description` | string | What the transaction was for. |
| `data.amount` | integer | The signed amount, in the minor units of `currency`: negative for an expense, positive for income. |
| `data.currency` | string | The ISO 4217 currency the amount is in. |
| `data.category` | object or null | The category, if one is set: its id and name. |
| `data.category.id` | string |  |
| `data.category.name` | string |  |
| `data.category_source` | "manual" \| "rule" \| "ai" \| "bank" \| null | Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none. |
| `data.labels` | array of object | The labels the transaction is tagged with: their ids and names. |
| `data.labels[].id` | string |  |
| `data.labels[].name` | string |  |
| `data.source` | "manually_created" \| "imported" \| "enablebanking" \| "wise" | Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, currency, account or counterparty names; notes and the category can be changed on any, the date on any but one part of a split, and any but one part of a split can be deleted. |
| `data.creditor_name` | string or null | Who was paid, or `null`. |
| `data.debtor_name` | string or null | Who paid, or `null`. |
| `data.notes` | string or null | Free-text notes, or `null`. |
| `data.split_parent_id` | string (uuid) or null | Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own. |
| `data.created_at` | string (date-time) or null | When the transaction was recorded in Whisper Money. |
| `balance_updated` | boolean | Whether `update_balance` moved the account's balance snapshots. |

#### Example request

```bash
curl -X PATCH https://whisper.money/api/v1/transactions/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "account_id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
    "description": "Groceries at the market",
    "amount": -4250,
    "transaction_date": "2026-01-31",
    "currency_code": "EUR",
    "creditor_name": "Fresh Market"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "account": {
            "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
            "name": "Main checking"
        },
        "transaction_date": "2026-01-31",
        "description": "Groceries at the market",
        "amount": -4250,
        "currency": "EUR",
        "category": {
            "id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f",
            "name": "Groceries"
        },
        "category_source": "manual",
        "labels": [
            {
                "id": "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a",
                "name": "Holidays"
            }
        ],
        "source": "manually_created",
        "creditor_name": "Fresh Market",
        "debtor_name": null,
        "notes": null,
        "split_parent_id": null,
        "created_at": "2026-01-31T18:12:00+00:00"
    },
    "balance_updated": true
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Delete a transaction

`DELETE /api/v1/transactions/{transaction}`

Deletes a transaction, one that came from a bank or an import included: as in the app, the next sync does not bring it back. One part of a split cannot be deleted on its own and answers `422` with the error under `transaction`.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `transaction` | path | string (uuid) | Yes | The transaction's id. |
| `update_balance` | query | boolean | No | Whether to take the transaction back out of the account's balance snapshots. Has no effect on a bank-connected account or a credit card; `balance_updated` in the response says whether they moved. Defaults to `false`. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `id` | string (uuid) | The deleted transaction's id. |
| `deleted` | boolean | Always `true`. |
| `balance_updated` | boolean | Whether `update_balance` took the transaction back out of the account's balance snapshots. |

#### Example request

```bash
curl -X DELETE https://whisper.money/api/v1/transactions/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
    "deleted": true,
    "balance_updated": true
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Replace the labels of a transaction

`PUT /api/v1/transactions/{transaction}/labels`

Sets the transaction's labels to exactly `label_ids`: a label it had that is not in the list is removed, and an empty list removes them all. Works on any transaction you own, including one from a bank or an import and one part of a split. Labelling is also how a transaction counts towards a savings goal: it does once it carries the goal's label.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `transaction` | path | string (uuid) | Yes | The transaction's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `label_ids` | array of string (uuid) | Yes | The ids of every label the transaction should carry, each one of your labels in the space, up to 100. The transaction ends up with exactly these: a label left out is removed, and an empty list removes them all. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Transaction |  |
| `data.id` | string (uuid) | The transaction's id. |
| `data.space_id` | string (uuid) or null | The id of the space the transaction is in, its account's. |
| `data.account` | object | The account the transaction is on: its id and name. |
| `data.account.id` | string |  |
| `data.account.name` | string |  |
| `data.transaction_date` | string (date) | The day the transaction happened. |
| `data.description` | string | What the transaction was for. |
| `data.amount` | integer | The signed amount, in the minor units of `currency`: negative for an expense, positive for income. |
| `data.currency` | string | The ISO 4217 currency the amount is in. |
| `data.category` | object or null | The category, if one is set: its id and name. |
| `data.category.id` | string |  |
| `data.category.name` | string |  |
| `data.category_source` | "manual" \| "rule" \| "ai" \| "bank" \| null | Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none. |
| `data.labels` | array of object | The labels the transaction is tagged with: their ids and names. |
| `data.labels[].id` | string |  |
| `data.labels[].name` | string |  |
| `data.source` | "manually_created" \| "imported" \| "enablebanking" \| "wise" | Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, currency, account or counterparty names; notes and the category can be changed on any, the date on any but one part of a split, and any but one part of a split can be deleted. |
| `data.creditor_name` | string or null | Who was paid, or `null`. |
| `data.debtor_name` | string or null | Who paid, or `null`. |
| `data.notes` | string or null | Free-text notes, or `null`. |
| `data.split_parent_id` | string (uuid) or null | Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own. |
| `data.created_at` | string (date-time) or null | When the transaction was recorded in Whisper Money. |

#### Example request

```bash
curl -X PUT https://whisper.money/api/v1/transactions/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60/labels \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "label_ids": [
        "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a"
    ]
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "account": {
            "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
            "name": "Main checking"
        },
        "transaction_date": "2026-01-31",
        "description": "Groceries at the market",
        "amount": -4250,
        "currency": "EUR",
        "category": {
            "id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f",
            "name": "Groceries"
        },
        "category_source": "manual",
        "labels": [
            {
                "id": "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a",
                "name": "Holidays"
            }
        ],
        "source": "manually_created",
        "creditor_name": "Fresh Market",
        "debtor_name": null,
        "notes": null,
        "split_parent_id": null,
        "created_at": "2026-01-31T18:12:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Split a transaction

`POST /api/v1/transactions/{transaction}/split`

Replaces a transaction that covered several things with 2 to 20 parts, each with its own amount, category and labels. Works on bank and imported transactions too. The parts must add up to the original exactly and all move money the same way. From then on the parts are what exists: the original is out of every list and every total, and each part carries a `split_parent_id` shared with its siblings. A part cannot be split again, deleted on its own, or have its amount, date or account changed; categorizing, labelling and editing its notes work as usual. Splitting a part again answers `422` with the error under `transaction`, and the original's id answers `404` once it is split. Answers the parts, in the order they were sent.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `transaction` | path | string (uuid) | Yes | The transaction's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `splits` | array of object | Yes | The parts to replace the transaction with, 2 to 20 of them. Their amounts must add up to the transaction's amount exactly. |
| `splits[].amount` | integer | Yes | The part's signed amount, in the minor units of the transaction's currency. It has the sign of the original: the parts of an expense are all negative. |
| `splits[].category_id` | string (uuid) or null | No | The id of the part's category, one of your categories in the space. Left out or `null`, the part is uncategorized. |
| `splits[].label_ids` | array of string (uuid) | No | The ids of the part's labels, each one of your labels in the space, up to 100. Defaults to none: the original's labels are not copied. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | array of Transaction |  |
| `data[].id` | string (uuid) | The transaction's id. |
| `data[].space_id` | string (uuid) or null | The id of the space the transaction is in, its account's. |
| `data[].account` | object | The account the transaction is on: its id and name. |
| `data[].account.id` | string |  |
| `data[].account.name` | string |  |
| `data[].transaction_date` | string (date) | The day the transaction happened. |
| `data[].description` | string | What the transaction was for. |
| `data[].amount` | integer | The signed amount, in the minor units of `currency`: negative for an expense, positive for income. |
| `data[].currency` | string | The ISO 4217 currency the amount is in. |
| `data[].category` | object or null | The category, if one is set: its id and name. |
| `data[].category.id` | string |  |
| `data[].category.name` | string |  |
| `data[].category_source` | "manual" \| "rule" \| "ai" \| "bank" \| null | Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none. |
| `data[].labels` | array of object | The labels the transaction is tagged with: their ids and names. |
| `data[].labels[].id` | string |  |
| `data[].labels[].name` | string |  |
| `data[].source` | "manually_created" \| "imported" \| "enablebanking" \| "wise" | Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, currency, account or counterparty names; notes and the category can be changed on any, the date on any but one part of a split, and any but one part of a split can be deleted. |
| `data[].creditor_name` | string or null | Who was paid, or `null`. |
| `data[].debtor_name` | string or null | Who paid, or `null`. |
| `data[].notes` | string or null | Free-text notes, or `null`. |
| `data[].split_parent_id` | string (uuid) or null | Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own. |
| `data[].created_at` | string (date-time) or null | When the transaction was recorded in Whisper Money. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/transactions/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60/split \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "splits": [
        {
            "amount": -3000,
            "category_id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f",
            "label_ids": [
                "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a"
            ]
        },
        {
            "amount": -1250
        }
    ]
}'
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
            "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "account": {
                "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
                "name": "Main checking"
            },
            "transaction_date": "2026-01-31",
            "description": "Groceries at the market",
            "amount": -4250,
            "currency": "EUR",
            "category": {
                "id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f",
                "name": "Groceries"
            },
            "category_source": "manual",
            "labels": [
                {
                    "id": "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a",
                    "name": "Holidays"
                }
            ],
            "source": "manually_created",
            "creditor_name": "Fresh Market",
            "debtor_name": null,
            "notes": null,
            "split_parent_id": null,
            "created_at": "2026-01-31T18:12:00+00:00"
        }
    ]
}
```

#### Errors

| Status | When |
| --- | --- |
| `400` | A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`). |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Merge a split back

`POST /api/v1/transactions/{transaction}/merge-splits`

Puts back the transaction a split came from, with the category it had before, and deletes every part along with the category, labels and notes set on them. Pass the id of any one of the parts; one that is not part of a split answers `422` with the error under `transaction`, and the original's own id answers `404`: it is out of every list until the merge. Answers the original transaction, under its id from before the split.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `transaction` | path | string (uuid) | Yes | The id of any part of the split. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Transaction |  |
| `data.id` | string (uuid) | The transaction's id. |
| `data.space_id` | string (uuid) or null | The id of the space the transaction is in, its account's. |
| `data.account` | object | The account the transaction is on: its id and name. |
| `data.account.id` | string |  |
| `data.account.name` | string |  |
| `data.transaction_date` | string (date) | The day the transaction happened. |
| `data.description` | string | What the transaction was for. |
| `data.amount` | integer | The signed amount, in the minor units of `currency`: negative for an expense, positive for income. |
| `data.currency` | string | The ISO 4217 currency the amount is in. |
| `data.category` | object or null | The category, if one is set: its id and name. |
| `data.category.id` | string |  |
| `data.category.name` | string |  |
| `data.category_source` | "manual" \| "rule" \| "ai" \| "bank" \| null | Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none. |
| `data.labels` | array of object | The labels the transaction is tagged with: their ids and names. |
| `data.labels[].id` | string |  |
| `data.labels[].name` | string |  |
| `data.source` | "manually_created" \| "imported" \| "enablebanking" \| "wise" | Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, currency, account or counterparty names; notes and the category can be changed on any, the date on any but one part of a split, and any but one part of a split can be deleted. |
| `data.creditor_name` | string or null | Who was paid, or `null`. |
| `data.debtor_name` | string or null | Who paid, or `null`. |
| `data.notes` | string or null | Free-text notes, or `null`. |
| `data.split_parent_id` | string (uuid) or null | Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own. |
| `data.created_at` | string (date-time) or null | When the transaction was recorded in Whisper Money. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/transactions/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60/merge-splits \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "account": {
            "id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
            "name": "Main checking"
        },
        "transaction_date": "2026-01-31",
        "description": "Groceries at the market",
        "amount": -4250,
        "currency": "EUR",
        "category": {
            "id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f",
            "name": "Groceries"
        },
        "category_source": "manual",
        "labels": [
            {
                "id": "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a",
                "name": "Holidays"
            }
        ],
        "source": "manually_created",
        "creditor_name": "Fresh Market",
        "debtor_name": null,
        "notes": null,
        "split_parent_id": null,
        "created_at": "2026-01-31T18:12:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `400` | A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`). |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |
