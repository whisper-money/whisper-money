# Budgets

A budget is a spending limit per period over some of your categories and labels. Budgets cover your whole account, not a space, and only ever track your own categories and labels. Amounts are in the minor units of your account's currency, given as `currency`. A budget's period length, start day, rollover and what it tracks are fixed once it is created: to change them, delete the budget and create it again.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

{{TOC}}

## List budgets

`GET /api/v1/budgets`

Your budgets, newest first, each with the period in progress. Archived budgets are left out; retrieve one by its id to read it.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `limit` | query | integer | No | How many objects to return, between 1 and 100. |
| `starting_after` | query | string (uuid) | No | An object id: return the page that follows it. Pass the last id of the previous page. |
| `ending_before` | query | string (uuid) | No | An object id: return the page that precedes it. Pass the first id of the next page. Cannot be combined with `starting_after`. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | array of Budget |  |
| `data[].id` | string (uuid) | The budget's id. |
| `data[].name` | string | The budget's name. |
| `data[].period_type` | "monthly" \| "weekly" \| "biweekly" \| "yearly" \| "quarterly" | How often the budget starts over: `monthly`, `weekly`, `biweekly`, `quarterly` or `yearly`. Fixed once created. |
| `data[].period_start_day` | integer or null | The day a period starts: the day of the month (1–31) for a monthly budget, the day of the week (0 is Sunday) for a weekly or biweekly one. Quarterly and yearly budgets follow the calendar. `null` when not set. Fixed once created. |
| `data[].rollover_type` | "carry_over" \| "reset" | What happens to what is left at the end of a period: `carry_over` adds it to the next period, `reset` starts every period at the limit. Fixed once created. |
| `data[].expense_type` | "fixed" \| "variable" | `fixed` for spending that repeats at the same amount every period (rent, subscriptions), `variable` for spending you can adjust (groceries, leisure). It decides how the cashflow analytics split your expenses. A catch-all budget is always `variable`. |
| `data[].is_catch_all` | boolean | Whether the budget tracks every expense category no other budget tracks. An account has at most one. Fixed once created. |
| `data[].categories` | array of object | The categories the budget tracks; tracking a parent category also tracks its children. Fixed once created. |
| `data[].categories[].id` | string |  |
| `data[].categories[].name` | string |  |
| `data[].labels` | array of object | The labels the budget tracks. Fixed once created. |
| `data[].labels[].id` | string |  |
| `data[].labels[].name` | string |  |
| `data[].currency` | string | The currency of the budget's amounts: your account's currency, as an ISO 4217 code. |
| `data[].current_period` | BudgetPeriod or null | The period that covers today, or `null` when no period does. |
| `data[].current_period.id` | string (uuid) | The period's id. |
| `data[].current_period.start_date` | string (date) | The period's first day. |
| `data[].current_period.end_date` | string (date) | The period's last day, included. |
| `data[].current_period.allocated_amount` | integer | The limit for this period. |
| `data[].current_period.carried_over_amount` | integer | What the previous period left over, carried into this one when the budget's `rollover_type` is `carry_over`. |
| `data[].current_period.spent_amount` | integer | What the tracked transactions of the period add up to, as a positive number; a refund lowers it. Provisional while `processing_historical` is `true`. |
| `data[].current_period.remaining_amount` | integer | `allocated_amount` minus `spent_amount`, the figure the app shows. It leaves out `carried_over_amount`, and it is negative once the period goes over its limit. |
| `data[].current_period.processing_historical` | boolean | `true` while the transactions that were already in the period when the budget was created are still being added to it, in the background. Until then `spent_amount` and `remaining_amount` are provisional: read the budget again in a few seconds. |
| `data[].archived_at` | string (date-time) or null | When the budget was archived in the app, or `null`. An archived budget keeps its history but can no longer change; it can still be deleted. |
| `data[].created_at` | string (date-time) or null | When the budget was created. |
| `data[].updated_at` | string (date-time) or null | When the budget was last changed. |
| `has_more` | boolean | Whether more objects exist past this page, in the direction being walked. |

#### Example request

```bash
curl https://whisper.money/api/v1/budgets \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
            "name": "Groceries",
            "period_type": "monthly",
            "period_start_day": 1,
            "rollover_type": "reset",
            "expense_type": "variable",
            "is_catch_all": true,
            "categories": [
                {
                    "id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
                    "name": "Groceries"
                }
            ],
            "labels": [
                {
                    "id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
                    "name": "Essentials"
                }
            ],
            "currency": "EUR",
            "current_period": {
                "id": "0199a3fa-1b2c-7d3e-8f4a-5b6c7d8e9f01",
                "start_date": "2026-10-01",
                "end_date": "2026-10-31",
                "allocated_amount": 50000,
                "carried_over_amount": 0,
                "spent_amount": 32050,
                "remaining_amount": 17950,
                "processing_historical": true
            },
            "archived_at": null,
            "created_at": "2026-01-15T09:30:00+00:00",
            "updated_at": "2026-01-20T18:05:00+00:00"
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
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`, `not_savings_goal_owner`). |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Create a budget

`POST /api/v1/budgets`

The budget needs at least one category or label to track, or `is_catch_all: true`. It starts with the period in progress and the one before it. The transactions already in them are added in the background, so `current_period.spent_amount` is provisional while `current_period.processing_historical` is `true`: read the budget again a few seconds later.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | Yes | The budget's name. |
| `allocated_amount` | integer | Yes | The limit for each period, in the minor units of your account's currency. |
| `period_type` | BudgetPeriodType | Yes | How often the budget starts over. |
| `rollover_type` | RolloverType | Yes | `carry_over` adds what is left at the end of a period to the next one, `reset` starts every period at the limit. |
| `period_start_day` | integer or null | No | The day a period starts: the day of the month (1–31) for a monthly budget, the day of the week (0 is Sunday, 6 Saturday) for a weekly or biweekly one. Quarterly and yearly budgets follow the calendar and ignore it. |
| `expense_type` | BudgetExpenseType | No | `fixed` for spending that repeats at the same amount every period (rent, subscriptions), `variable` (the default) for spending you can adjust. A catch-all budget is always `variable`. |
| `is_catch_all` | boolean | No | Track every expense category no other budget tracks. A catch-all budget takes no `category_ids` or `label_ids`, and an account has at most one. |
| `category_ids` | array of string (uuid) | No | The ids of the categories to track, each one of your own, 100 at most. Tracking a parent also tracks its children. A budget needs at least one category or label, unless it is the catch-all. |
| `label_ids` | array of string (uuid) | No | The ids of the labels to track, each one of your own, 100 at most. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Budget |  |
| `data.id` | string (uuid) | The budget's id. |
| `data.name` | string | The budget's name. |
| `data.period_type` | "monthly" \| "weekly" \| "biweekly" \| "yearly" \| "quarterly" | How often the budget starts over: `monthly`, `weekly`, `biweekly`, `quarterly` or `yearly`. Fixed once created. |
| `data.period_start_day` | integer or null | The day a period starts: the day of the month (1–31) for a monthly budget, the day of the week (0 is Sunday) for a weekly or biweekly one. Quarterly and yearly budgets follow the calendar. `null` when not set. Fixed once created. |
| `data.rollover_type` | "carry_over" \| "reset" | What happens to what is left at the end of a period: `carry_over` adds it to the next period, `reset` starts every period at the limit. Fixed once created. |
| `data.expense_type` | "fixed" \| "variable" | `fixed` for spending that repeats at the same amount every period (rent, subscriptions), `variable` for spending you can adjust (groceries, leisure). It decides how the cashflow analytics split your expenses. A catch-all budget is always `variable`. |
| `data.is_catch_all` | boolean | Whether the budget tracks every expense category no other budget tracks. An account has at most one. Fixed once created. |
| `data.categories` | array of object | The categories the budget tracks; tracking a parent category also tracks its children. Fixed once created. |
| `data.categories[].id` | string |  |
| `data.categories[].name` | string |  |
| `data.labels` | array of object | The labels the budget tracks. Fixed once created. |
| `data.labels[].id` | string |  |
| `data.labels[].name` | string |  |
| `data.currency` | string | The currency of the budget's amounts: your account's currency, as an ISO 4217 code. |
| `data.current_period` | BudgetPeriod or null | The period that covers today, or `null` when no period does. |
| `data.current_period.id` | string (uuid) | The period's id. |
| `data.current_period.start_date` | string (date) | The period's first day. |
| `data.current_period.end_date` | string (date) | The period's last day, included. |
| `data.current_period.allocated_amount` | integer | The limit for this period. |
| `data.current_period.carried_over_amount` | integer | What the previous period left over, carried into this one when the budget's `rollover_type` is `carry_over`. |
| `data.current_period.spent_amount` | integer | What the tracked transactions of the period add up to, as a positive number; a refund lowers it. Provisional while `processing_historical` is `true`. |
| `data.current_period.remaining_amount` | integer | `allocated_amount` minus `spent_amount`, the figure the app shows. It leaves out `carried_over_amount`, and it is negative once the period goes over its limit. |
| `data.current_period.processing_historical` | boolean | `true` while the transactions that were already in the period when the budget was created are still being added to it, in the background. Until then `spent_amount` and `remaining_amount` are provisional: read the budget again in a few seconds. |
| `data.archived_at` | string (date-time) or null | When the budget was archived in the app, or `null`. An archived budget keeps its history but can no longer change; it can still be deleted. |
| `data.created_at` | string (date-time) or null | When the budget was created. |
| `data.updated_at` | string (date-time) or null | When the budget was last changed. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/budgets \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Groceries",
    "allocated_amount": 50000,
    "period_type": "monthly",
    "rollover_type": "carry_over",
    "period_start_day": 1,
    "is_catch_all": false,
    "category_ids": [
        "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12"
    ],
    "label_ids": [
        "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"
    ]
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
        "name": "Groceries",
        "period_type": "monthly",
        "period_start_day": 1,
        "rollover_type": "reset",
        "expense_type": "variable",
        "is_catch_all": true,
        "categories": [
            {
                "id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
                "name": "Groceries"
            }
        ],
        "labels": [
            {
                "id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
                "name": "Essentials"
            }
        ],
        "currency": "EUR",
        "current_period": {
            "id": "0199a3fa-1b2c-7d3e-8f4a-5b6c7d8e9f01",
            "start_date": "2026-10-01",
            "end_date": "2026-10-31",
            "allocated_amount": 50000,
            "carried_over_amount": 0,
            "spent_amount": 32050,
            "remaining_amount": 17950,
            "processing_historical": true
        },
        "archived_at": null,
        "created_at": "2026-01-15T09:30:00+00:00",
        "updated_at": "2026-01-20T18:05:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `400` | A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`). |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`, `not_savings_goal_owner`). |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Retrieve a budget

`GET /api/v1/budgets/{budget}`

Archived budgets included.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `budget` | path | string (uuid) | Yes | The budget's id. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Budget |  |
| `data.id` | string (uuid) | The budget's id. |
| `data.name` | string | The budget's name. |
| `data.period_type` | "monthly" \| "weekly" \| "biweekly" \| "yearly" \| "quarterly" | How often the budget starts over: `monthly`, `weekly`, `biweekly`, `quarterly` or `yearly`. Fixed once created. |
| `data.period_start_day` | integer or null | The day a period starts: the day of the month (1–31) for a monthly budget, the day of the week (0 is Sunday) for a weekly or biweekly one. Quarterly and yearly budgets follow the calendar. `null` when not set. Fixed once created. |
| `data.rollover_type` | "carry_over" \| "reset" | What happens to what is left at the end of a period: `carry_over` adds it to the next period, `reset` starts every period at the limit. Fixed once created. |
| `data.expense_type` | "fixed" \| "variable" | `fixed` for spending that repeats at the same amount every period (rent, subscriptions), `variable` for spending you can adjust (groceries, leisure). It decides how the cashflow analytics split your expenses. A catch-all budget is always `variable`. |
| `data.is_catch_all` | boolean | Whether the budget tracks every expense category no other budget tracks. An account has at most one. Fixed once created. |
| `data.categories` | array of object | The categories the budget tracks; tracking a parent category also tracks its children. Fixed once created. |
| `data.categories[].id` | string |  |
| `data.categories[].name` | string |  |
| `data.labels` | array of object | The labels the budget tracks. Fixed once created. |
| `data.labels[].id` | string |  |
| `data.labels[].name` | string |  |
| `data.currency` | string | The currency of the budget's amounts: your account's currency, as an ISO 4217 code. |
| `data.current_period` | BudgetPeriod or null | The period that covers today, or `null` when no period does. |
| `data.current_period.id` | string (uuid) | The period's id. |
| `data.current_period.start_date` | string (date) | The period's first day. |
| `data.current_period.end_date` | string (date) | The period's last day, included. |
| `data.current_period.allocated_amount` | integer | The limit for this period. |
| `data.current_period.carried_over_amount` | integer | What the previous period left over, carried into this one when the budget's `rollover_type` is `carry_over`. |
| `data.current_period.spent_amount` | integer | What the tracked transactions of the period add up to, as a positive number; a refund lowers it. Provisional while `processing_historical` is `true`. |
| `data.current_period.remaining_amount` | integer | `allocated_amount` minus `spent_amount`, the figure the app shows. It leaves out `carried_over_amount`, and it is negative once the period goes over its limit. |
| `data.current_period.processing_historical` | boolean | `true` while the transactions that were already in the period when the budget was created are still being added to it, in the background. Until then `spent_amount` and `remaining_amount` are provisional: read the budget again in a few seconds. |
| `data.archived_at` | string (date-time) or null | When the budget was archived in the app, or `null`. An archived budget keeps its history but can no longer change; it can still be deleted. |
| `data.created_at` | string (date-time) or null | When the budget was created. |
| `data.updated_at` | string (date-time) or null | When the budget was last changed. |

#### Example request

```bash
curl https://whisper.money/api/v1/budgets/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
        "name": "Groceries",
        "period_type": "monthly",
        "period_start_day": 1,
        "rollover_type": "reset",
        "expense_type": "variable",
        "is_catch_all": true,
        "categories": [
            {
                "id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
                "name": "Groceries"
            }
        ],
        "labels": [
            {
                "id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
                "name": "Essentials"
            }
        ],
        "currency": "EUR",
        "current_period": {
            "id": "0199a3fa-1b2c-7d3e-8f4a-5b6c7d8e9f01",
            "start_date": "2026-10-01",
            "end_date": "2026-10-31",
            "allocated_amount": 50000,
            "carried_over_amount": 0,
            "spent_amount": 32050,
            "remaining_amount": 17950,
            "processing_historical": true
        },
        "archived_at": null,
        "created_at": "2026-01-15T09:30:00+00:00",
        "updated_at": "2026-01-20T18:05:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`, `not_savings_goal_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Update a budget

`PATCH /api/v1/budgets/{budget}`

Changes only the fields you send: the name, the limit and the expense type. A new `allocated_amount` applies to the period in progress and every later one; past periods keep theirs. `period_type`, `period_start_day`, `rollover_type`, `is_catch_all`, `category_ids` and `label_ids` are fixed, since the budget's periods are calculated over its history: sending any of them is a `422` under that field, and the way to change them is to delete the budget and create it again. An archived budget can no longer change: `422` under `budget`.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `budget` | path | string (uuid) | Yes | The budget's id. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | No | The budget's new name. |
| `allocated_amount` | integer | No | The new limit, in the minor units of your account's currency. It applies to the period in progress and every later one; past periods keep theirs. |
| `expense_type` | BudgetExpenseType | No | `fixed` or `variable`. A catch-all budget stays `variable` whatever you send. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Budget |  |
| `data.id` | string (uuid) | The budget's id. |
| `data.name` | string | The budget's name. |
| `data.period_type` | "monthly" \| "weekly" \| "biweekly" \| "yearly" \| "quarterly" | How often the budget starts over: `monthly`, `weekly`, `biweekly`, `quarterly` or `yearly`. Fixed once created. |
| `data.period_start_day` | integer or null | The day a period starts: the day of the month (1–31) for a monthly budget, the day of the week (0 is Sunday) for a weekly or biweekly one. Quarterly and yearly budgets follow the calendar. `null` when not set. Fixed once created. |
| `data.rollover_type` | "carry_over" \| "reset" | What happens to what is left at the end of a period: `carry_over` adds it to the next period, `reset` starts every period at the limit. Fixed once created. |
| `data.expense_type` | "fixed" \| "variable" | `fixed` for spending that repeats at the same amount every period (rent, subscriptions), `variable` for spending you can adjust (groceries, leisure). It decides how the cashflow analytics split your expenses. A catch-all budget is always `variable`. |
| `data.is_catch_all` | boolean | Whether the budget tracks every expense category no other budget tracks. An account has at most one. Fixed once created. |
| `data.categories` | array of object | The categories the budget tracks; tracking a parent category also tracks its children. Fixed once created. |
| `data.categories[].id` | string |  |
| `data.categories[].name` | string |  |
| `data.labels` | array of object | The labels the budget tracks. Fixed once created. |
| `data.labels[].id` | string |  |
| `data.labels[].name` | string |  |
| `data.currency` | string | The currency of the budget's amounts: your account's currency, as an ISO 4217 code. |
| `data.current_period` | BudgetPeriod or null | The period that covers today, or `null` when no period does. |
| `data.current_period.id` | string (uuid) | The period's id. |
| `data.current_period.start_date` | string (date) | The period's first day. |
| `data.current_period.end_date` | string (date) | The period's last day, included. |
| `data.current_period.allocated_amount` | integer | The limit for this period. |
| `data.current_period.carried_over_amount` | integer | What the previous period left over, carried into this one when the budget's `rollover_type` is `carry_over`. |
| `data.current_period.spent_amount` | integer | What the tracked transactions of the period add up to, as a positive number; a refund lowers it. Provisional while `processing_historical` is `true`. |
| `data.current_period.remaining_amount` | integer | `allocated_amount` minus `spent_amount`, the figure the app shows. It leaves out `carried_over_amount`, and it is negative once the period goes over its limit. |
| `data.current_period.processing_historical` | boolean | `true` while the transactions that were already in the period when the budget was created are still being added to it, in the background. Until then `spent_amount` and `remaining_amount` are provisional: read the budget again in a few seconds. |
| `data.archived_at` | string (date-time) or null | When the budget was archived in the app, or `null`. An archived budget keeps its history but can no longer change; it can still be deleted. |
| `data.created_at` | string (date-time) or null | When the budget was created. |
| `data.updated_at` | string (date-time) or null | When the budget was last changed. |

#### Example request

```bash
curl -X PATCH https://whisper.money/api/v1/budgets/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Food",
    "allocated_amount": 60000
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
        "name": "Groceries",
        "period_type": "monthly",
        "period_start_day": 1,
        "rollover_type": "reset",
        "expense_type": "variable",
        "is_catch_all": true,
        "categories": [
            {
                "id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
                "name": "Groceries"
            }
        ],
        "labels": [
            {
                "id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
                "name": "Essentials"
            }
        ],
        "currency": "EUR",
        "current_period": {
            "id": "0199a3fa-1b2c-7d3e-8f4a-5b6c7d8e9f01",
            "start_date": "2026-10-01",
            "end_date": "2026-10-31",
            "allocated_amount": 50000,
            "carried_over_amount": 0,
            "spent_amount": 32050,
            "remaining_amount": 17950,
            "processing_historical": true
        },
        "archived_at": null,
        "created_at": "2026-01-15T09:30:00+00:00",
        "updated_at": "2026-01-20T18:05:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`, `not_savings_goal_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | Validation error |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Delete a budget

`DELETE /api/v1/budgets/{budget}`

Deletes the budget with its periods and their spending history, archived budgets included. The transactions it tracked are untouched.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `budget` | path | string (uuid) | Yes | The budget's id. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `id` | string (uuid) | The deleted budget's id. |
| `deleted` | boolean | Always `true`. |

#### Example request

```bash
curl -X DELETE https://whisper.money/api/v1/budgets/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
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
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`, `not_savings_goal_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | Validation error |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |
