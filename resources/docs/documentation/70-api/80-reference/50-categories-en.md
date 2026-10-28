# Categories

A category says what a transaction was for. Each transaction has at most one. Categories form a tree up to 3 levels deep through `parent_id`, and a whole tree shares its root's type and cashflow direction. A budget that tracks a parent category also tracks its children.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

{{TOC}}

## List categories

`GET /api/v1/categories`

The categories in a space, newest first, roots and children alike: rebuild the tree from `parent_id`. Every member of a space sees all of its categories.

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
| `data` | array of Category |  |
| `data[].id` | string (uuid) | The category's id. |
| `data[].space_id` | string (uuid) or null | The id of the space the category is in. |
| `data[].name` | string | The category's name, unique among its siblings. |
| `data[].icon` | string | The name of the icon the app shows for it, a Lucide icon name. |
| `data[].color` | string | The category's color. |
| `data[].type` | "income" \| "expense" \| "transfer" \| "savings" \| "investment" | What the category's transactions are: `income`, `expense`, `transfer`, `savings` or `investment`. A whole tree shares its root's type. |
| `data[].cashflow_direction` | "hidden" \| "inflow" \| "outflow" | How the category's transactions count in the cashflow: `inflow`, `outflow`, or `hidden` to leave them out of it. A whole tree shares its root's direction. |
| `data[].parent_id` | string (uuid) or null | The id of the category it is nested under, or `null` for a root. |
| `data[].owned` | boolean | Whether you created the category. Every member of its space reads it and can use it, but only the one who created it can change or delete it. |
| `data[].created_at` | string (date-time) or null | When the category was created. |
| `has_more` | boolean | Whether more objects exist past this page, in the direction being walked. |

#### Example request

```bash
curl https://whisper.money/api/v1/categories \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
            "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "name": "Groceries",
            "icon": "ShoppingBag",
            "color": "green",
            "type": "expense",
            "cashflow_direction": "hidden",
            "parent_id": null,
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

## Create a category

`POST /api/v1/categories`

A root category takes the `type` you send, and its cashflow direction follows from it: `savings` and `investment` count as an outflow, a `transfer` keeps the `cashflow_direction` you send (`hidden` when you send none) and the rest are left out of the cashflow. A child, created with a `parent_id`, takes its parent's type and direction whatever you send.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | Yes | The category's name, unique among its siblings: two categories can share a name only under different parents. |
| `icon` | string | Yes | The name of the icon the app shows for it, a Lucide icon name. |
| `color` | CategoryColor | Yes | The category's color. |
| `type` | CategoryType | Yes | What the category's transactions are: `income`, `expense`, `transfer`, `savings` or `investment`. Ignored on a child, which always takes its parent's type. |
| `cashflow_direction` | CategoryCashflowDirection | No | How the category's transactions count in the cashflow. Only a root `transfer` category keeps the one you send: a `savings` or `investment` root is always `outflow`, any other root `hidden`, and a child takes its parent's. |
| `parent_id` | string (uuid) or null | No | The id of the category to nest it under, or `null` for a root. Categories nest up to 3 levels deep, and a category cannot sit under itself or one of its own children. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Category |  |
| `data.id` | string (uuid) | The category's id. |
| `data.space_id` | string (uuid) or null | The id of the space the category is in. |
| `data.name` | string | The category's name, unique among its siblings. |
| `data.icon` | string | The name of the icon the app shows for it, a Lucide icon name. |
| `data.color` | string | The category's color. |
| `data.type` | "income" \| "expense" \| "transfer" \| "savings" \| "investment" | What the category's transactions are: `income`, `expense`, `transfer`, `savings` or `investment`. A whole tree shares its root's type. |
| `data.cashflow_direction` | "hidden" \| "inflow" \| "outflow" | How the category's transactions count in the cashflow: `inflow`, `outflow`, or `hidden` to leave them out of it. A whole tree shares its root's direction. |
| `data.parent_id` | string (uuid) or null | The id of the category it is nested under, or `null` for a root. |
| `data.owned` | boolean | Whether you created the category. Every member of its space reads it and can use it, but only the one who created it can change or delete it. |
| `data.created_at` | string (date-time) or null | When the category was created. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/categories \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Groceries",
    "icon": "ShoppingBag",
    "color": "amber",
    "type": "income"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Groceries",
        "icon": "ShoppingBag",
        "color": "green",
        "type": "expense",
        "cashflow_direction": "hidden",
        "parent_id": null,
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

## Retrieve a category

`GET /api/v1/categories/{category}`

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `category` | path | string (uuid) | Yes | The category's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Category |  |
| `data.id` | string (uuid) | The category's id. |
| `data.space_id` | string (uuid) or null | The id of the space the category is in. |
| `data.name` | string | The category's name, unique among its siblings. |
| `data.icon` | string | The name of the icon the app shows for it, a Lucide icon name. |
| `data.color` | string | The category's color. |
| `data.type` | "income" \| "expense" \| "transfer" \| "savings" \| "investment" | What the category's transactions are: `income`, `expense`, `transfer`, `savings` or `investment`. A whole tree shares its root's type. |
| `data.cashflow_direction` | "hidden" \| "inflow" \| "outflow" | How the category's transactions count in the cashflow: `inflow`, `outflow`, or `hidden` to leave them out of it. A whole tree shares its root's direction. |
| `data.parent_id` | string (uuid) or null | The id of the category it is nested under, or `null` for a root. |
| `data.owned` | boolean | Whether you created the category. Every member of its space reads it and can use it, but only the one who created it can change or delete it. |
| `data.created_at` | string (date-time) or null | When the category was created. |

#### Example request

```bash
curl https://whisper.money/api/v1/categories/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Groceries",
        "icon": "ShoppingBag",
        "color": "green",
        "type": "expense",
        "cashflow_direction": "hidden",
        "parent_id": null,
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

## Update a category

`PATCH /api/v1/categories/{category}`

Changes only the fields you send. Only the member of the space who created the category can change it. Moving it under another parent, or making it a root with `parent_id: null`, re-derives its type and cashflow direction as on create, and its whole subtree follows: its children and their children take its new type and direction. While it has a parent, `type` and `cashflow_direction` are ignored.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `category` | path | string (uuid) | Yes | The category's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | No | The category's name, unique among its siblings: two categories can share a name only under different parents. |
| `icon` | string | No | The name of the icon the app shows for it, a Lucide icon name. |
| `color` | CategoryColor | No | The category's color. |
| `type` | CategoryType | No | What the category's transactions are: `income`, `expense`, `transfer`, `savings` or `investment`. Ignored on a child, which always takes its parent's type. |
| `cashflow_direction` | CategoryCashflowDirection | No | How the category's transactions count in the cashflow. Only a root `transfer` category keeps the one you send: a `savings` or `investment` root is always `outflow`, any other root `hidden`, and a child takes its parent's. |
| `parent_id` | string (uuid) or null | No | The id of the category to nest it under, or `null` for a root. Categories nest up to 3 levels deep, and a category cannot sit under itself or one of its own children. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Category |  |
| `data.id` | string (uuid) | The category's id. |
| `data.space_id` | string (uuid) or null | The id of the space the category is in. |
| `data.name` | string | The category's name, unique among its siblings. |
| `data.icon` | string | The name of the icon the app shows for it, a Lucide icon name. |
| `data.color` | string | The category's color. |
| `data.type` | "income" \| "expense" \| "transfer" \| "savings" \| "investment" | What the category's transactions are: `income`, `expense`, `transfer`, `savings` or `investment`. A whole tree shares its root's type. |
| `data.cashflow_direction` | "hidden" \| "inflow" \| "outflow" | How the category's transactions count in the cashflow: `inflow`, `outflow`, or `hidden` to leave them out of it. A whole tree shares its root's direction. |
| `data.parent_id` | string (uuid) or null | The id of the category it is nested under, or `null` for a root. |
| `data.owned` | boolean | Whether you created the category. Every member of its space reads it and can use it, but only the one who created it can change or delete it. |
| `data.created_at` | string (date-time) or null | When the category was created. |

#### Example request

```bash
curl -X PATCH https://whisper.money/api/v1/categories/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Groceries",
    "icon": "ShoppingBag"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Groceries",
        "icon": "ShoppingBag",
        "color": "green",
        "type": "expense",
        "cashflow_direction": "hidden",
        "parent_id": null,
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

## Delete a category

`DELETE /api/v1/categories/{category}`

Deletes the category and takes it off its transactions, which are left uncategorized. Only the member of the space who created it can delete it. `strategy` decides what happens to its children; when moving them would leave two categories with the same name at one level, nothing is deleted and the answer is a `422` under `strategy`: rename one first.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `category` | path | string (uuid) | Yes | The category's id. |
| `strategy` | query | CategoryDeletionStrategy | No | What happens to the category's children: `reparent` moves them up to its parent (or makes them roots when it is one), `promote` makes them roots, `cascade` deletes them too, with their own children. Defaults to `reparent`. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `id` | string (uuid) | The deleted category's id. |
| `deleted` | boolean | Always `true`. |
| `strategy` | "reparent" \| "promote" \| "cascade" | The strategy that was applied to the children. |

#### Example request

```bash
curl -X DELETE https://whisper.money/api/v1/categories/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
    "deleted": true,
    "strategy": "reparent"
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
