# Labels

A label is a free tag: a transaction can carry any number of them next to its single category. Labels are also how a savings goal counts its contributions, and budgets and automation rules can use them. Names are unique within a space.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

{{TOC}}

## List labels

`GET /api/v1/labels`

The labels in a space, newest first, the ones that back a savings goal included. Every member of a space sees all of its labels.

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
| `data` | array of Label |  |
| `data[].id` | string (uuid) | The label's id. |
| `data[].space_id` | string (uuid) or null | The id of the space the label is in. |
| `data[].name` | string | The label's name, unique within the space. |
| `data[].color` | string | The label's color. |
| `data[].source` | "user" \| "saving_goal" | Where the label comes from: `user` for one made by hand, `saving_goal` for the one a savings goal tracks its contributions with. |
| `data[].owned` | boolean | Whether you created the label. Every member of its space reads it and can tag with it, but only the one who created it can change or delete it. |
| `data[].created_at` | string (date-time) or null | When the label was created. |
| `has_more` | boolean | Whether more objects exist past this page, in the direction being walked. |

#### Example request

```bash
curl https://whisper.money/api/v1/labels \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
            "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "name": "Holidays",
            "color": "blue",
            "source": "user",
            "owned": true,
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
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`). |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Create a label

`POST /api/v1/labels`

Tag transactions with it through `PUT /api/v1/transactions/{id}/labels`.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | Yes | The label's name, unique within the space. |
| `color` | LabelColor | Yes | The label's color. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Label |  |
| `data.id` | string (uuid) | The label's id. |
| `data.space_id` | string (uuid) or null | The id of the space the label is in. |
| `data.name` | string | The label's name, unique within the space. |
| `data.color` | string | The label's color. |
| `data.source` | "user" \| "saving_goal" | Where the label comes from: `user` for one made by hand, `saving_goal` for the one a savings goal tracks its contributions with. |
| `data.owned` | boolean | Whether you created the label. Every member of its space reads it and can tag with it, but only the one who created it can change or delete it. |
| `data.created_at` | string (date-time) or null | When the label was created. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/labels \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Holidays",
    "color": "amber"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Holidays",
        "color": "blue",
        "source": "user",
        "owned": true,
        "created_at": "2026-01-15T09:30:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `400` | A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`). |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`). |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Retrieve a label

`GET /api/v1/labels/{label}`

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `label` | path | string (uuid) | Yes | The label's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Label |  |
| `data.id` | string (uuid) | The label's id. |
| `data.space_id` | string (uuid) or null | The id of the space the label is in. |
| `data.name` | string | The label's name, unique within the space. |
| `data.color` | string | The label's color. |
| `data.source` | "user" \| "saving_goal" | Where the label comes from: `user` for one made by hand, `saving_goal` for the one a savings goal tracks its contributions with. |
| `data.owned` | boolean | Whether you created the label. Every member of its space reads it and can tag with it, but only the one who created it can change or delete it. |
| `data.created_at` | string (date-time) or null | When the label was created. |

#### Example request

```bash
curl https://whisper.money/api/v1/labels/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Holidays",
        "color": "blue",
        "source": "user",
        "owned": true,
        "created_at": "2026-01-15T09:30:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Update a label

`PATCH /api/v1/labels/{label}`

Changes only the fields you send. Only the member of the space who created the label can change it.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `label` | path | string (uuid) | Yes | The label's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | No | The label's name, unique within the space. |
| `color` | LabelColor | No | The label's color. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Label |  |
| `data.id` | string (uuid) | The label's id. |
| `data.space_id` | string (uuid) or null | The id of the space the label is in. |
| `data.name` | string | The label's name, unique within the space. |
| `data.color` | string | The label's color. |
| `data.source` | "user" \| "saving_goal" | Where the label comes from: `user` for one made by hand, `saving_goal` for the one a savings goal tracks its contributions with. |
| `data.owned` | boolean | Whether you created the label. Every member of its space reads it and can tag with it, but only the one who created it can change or delete it. |
| `data.created_at` | string (date-time) or null | When the label was created. |

#### Example request

```bash
curl -X PATCH https://whisper.money/api/v1/labels/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Holidays"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Holidays",
        "color": "blue",
        "source": "user",
        "owned": true,
        "created_at": "2026-01-15T09:30:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Delete a label

`DELETE /api/v1/labels/{label}`

Deletes the label and takes it off every transaction that carried it. Only the member of the space who created it can delete it.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `label` | path | string (uuid) | Yes | The label's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `id` | string (uuid) | The deleted label's id. |
| `deleted` | boolean | Always `true`. |

#### Example request

```bash
curl -X DELETE https://whisper.money/api/v1/labels/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
    "deleted": true
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |
