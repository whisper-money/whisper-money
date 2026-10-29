# Balances

A balance is a snapshot of what an account held at the end of one day, in the account currency's minor units. A bank-connected account gets its balances from the bank sync; you record those of a manual account yourself. A credit card has no balance: it has a credit limit, and what is in use of it comes from its transactions.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

The example requests read your personal token from `$WHISPER_MONEY_TOKEN`: run `export WHISPER_MONEY_TOKEN=wm_api_your_token_here` before pasting them.

{{TOC}}

## List an account's balances

`GET /api/v1/accounts/{account}/balances`

The account's balance snapshots, the most recent day first.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `account` | path | string (uuid) | Yes | The account's id. |
| `limit` | query | integer | No | How many objects to return, between 1 and 100. Defaults to 25. |
| `starting_after` | query | string (uuid) | No | An object id: return the page that follows it. Pass the last id of the page you have. |
| `ending_before` | query | string (uuid) | No | An object id: return the page that precedes it. Pass the first id of the page you have. Cannot be combined with `starting_after`. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | array of AccountBalance |  |
| `data[].id` | string (uuid) | The snapshot's id. |
| `data[].account_id` | string (uuid) | The id of the account the snapshot belongs to. |
| `data[].balance_date` | string (date) | The day the balance is for. An account has at most one snapshot per day. |
| `data[].balance` | integer | The balance, in the account currency's minor units. |
| `data[].invested_amount` | integer or null | How much of the balance is money put in rather than gains, in minor units, or `null`. Set on investment accounts. |
| `data[].derived` | boolean | Whether the snapshot was worked out backwards from a bank-connected account's transactions, rather than reported by the bank or recorded by you. A balance recorded through the API is never derived. |
| `has_more` | boolean | Whether more objects exist past this page, in the direction being walked. |

#### Example request

```bash
curl https://whisper.money/api/v1/accounts/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60/balances \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3f6-2b4c-7d5e-9f60-718293a4b5c6",
            "account_id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
            "balance_date": "2026-01-31",
            "balance": 250000,
            "invested_amount": null,
            "derived": true
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
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Record a balance

`POST /api/v1/accounts/{account}/balances`

Records what a manual account held on a day, replacing the snapshot already recorded for that day. Answers `201` when it adds a snapshot and `200` when it replaces one. A bank-connected account is refused, since the next sync would overwrite the figure, and so is a credit card, which has no balance; both answer `422` with the error under `account`.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `account` | path | string (uuid) | Yes | The account's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `balance` | integer | Yes | The balance, in the account currency's minor units. |
| `balance_date` | string (date) | No | The day the balance is for. Defaults to today. |
| `invested_amount` | integer or null | No | How much of the balance is money put in rather than gains, in minor units, for investment accounts. Left out, the one already recorded for that day stays. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | AccountBalance |  |
| `data.id` | string (uuid) | The snapshot's id. |
| `data.account_id` | string (uuid) | The id of the account the snapshot belongs to. |
| `data.balance_date` | string (date) | The day the balance is for. An account has at most one snapshot per day. |
| `data.balance` | integer | The balance, in the account currency's minor units. |
| `data.invested_amount` | integer or null | How much of the balance is money put in rather than gains, in minor units, or `null`. Set on investment accounts. |
| `data.derived` | boolean | Whether the snapshot was worked out backwards from a bank-connected account's transactions, rather than reported by the bank or recorded by you. A balance recorded through the API is never derived. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/accounts/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60/balances \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "balance": 250000,
    "balance_date": "2026-01-31"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f6-2b4c-7d5e-9f60-718293a4b5c6",
        "account_id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01",
        "balance_date": "2026-01-31",
        "balance": 250000,
        "invested_amount": null,
        "derived": true
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
