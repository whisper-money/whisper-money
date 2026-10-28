# Savings goals

A savings goal is tracked through a label: its contributions are the transactions tagged with it. A `one_off` goal saves towards a total, optionally by a date. A `monthly` goal starts over every calendar month with a target that is a fixed `amount` or an `income_rate`, a share of the average income of the three previous complete months, frozen when the month opens. A goal's kind is fixed once created. Archiving and deleting goals happen in the Whisper Money app.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

The example requests read your personal token from `$WHISPER_MONEY_TOKEN`: run `export WHISPER_MONEY_TOKEN=wm_api_your_token_here` before pasting them.

{{TOC}}

## List savings goals

`GET /api/v1/savings-goals`

Your goals in a space, newest first, archived ones included. As in the app, another member's goals are not listed.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `limit` | query | integer | No | How many objects to return, between 1 and 100. Defaults to 25. |
| `starting_after` | query | string (uuid) | No | An object id: return the page that follows it. Pass the last id of the page you have. |
| `ending_before` | query | string (uuid) | No | An object id: return the page that precedes it. Pass the first id of the page you have. Cannot be combined with `starting_after`. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | array of SavingsGoal |  |
| `data[].id` | string (uuid) | The goal's id. |
| `data[].space_id` | string (uuid) or null | The id of the space the goal is in. |
| `data[].name` | string | The goal's name, which is also the name of its label. |
| `data[].kind` | "one_off" \| "monthly" | `one_off` to reach a total once, `monthly` to save towards a target every calendar month. Fixed once created. |
| `data[].label_id` | string (uuid) or null | The id of the goal's label. A transaction counts towards the goal once it carries this label: set it with `PUT /api/v1/transactions/{id}/labels`. |
| `data[].currency` | string | The currency of the goal's amounts: the currency of the account of the member who created it, as an ISO 4217 code. |
| `data[].target_amount` | integer or null | One-off goals: the total to reach. `null` on a monthly goal. |
| `data[].initial_amount` | integer or null | One-off goals: what was already saved when the goal was created. `null` on a monthly goal. |
| `data[].target_date` | string (date) or null | One-off goals: the day to reach the total by, or `null` for none. |
| `data[].progress` | object or null | One-off goals: where the goal stands. `saved` and `target` are amounts, `percentage` how much of the target is saved, `rate_per_day` what was added per day since the goal started, `status` (`completed`, `ahead`, `on_track` or `behind`, `null` without a target date) its pace against the target date, `expected_today` what that pace asks to have saved by now, `estimated_date` when the current rate reaches the target and `required_per_month` what is left to save each month to make the target date. `null` on a monthly goal. |
| `data[].progress.saved` | integer |  |
| `data[].progress.target` | integer |  |
| `data[].progress.percentage` | number |  |
| `data[].progress.target_date` | string or null |  |
| `data[].progress.rate_per_day` | number |  |
| `data[].progress.expected_today` | integer or null |  |
| `data[].progress.status` | string or null |  |
| `data[].progress.estimated_date` | string or null |  |
| `data[].progress.required_per_month` | integer or null |  |
| `data[].monthly_target_type` | "amount" \| "income_rate" \| null | Monthly goals: `amount` for a fixed sum each month, `income_rate` for a share of the average income of the three previous complete months, frozen when the month opens. `null` on a one-off goal. |
| `data[].monthly_target_amount` | integer or null | Monthly goals with an `amount` target: the sum to save each month. `null` otherwise. |
| `data[].monthly_target_rate` | number or null | Monthly goals with an `income_rate` target: the percentage of income to save each month. `null` otherwise. |
| `data[].notify_on_month_end_reminder` | boolean or null | Monthly goals: whether you get an email 5 days before the month ends while the month is short of its target. `null` on a one-off goal. |
| `data[].auto_tag_account_id` | string (uuid) or null | Monthly goals: the savings account whose incoming transfers an automation rule tags to this goal, or `null` for none. |
| `data[].monthly` | object or null | Monthly goals: each month judged against its own target, with nothing carried over between months. `current` is the month in progress (or `null`), with what is `remaining` and the `days_left`; `history` lists every month since the goal was created, oldest first. Each month has its `target`, `saved`, signed `difference`, `income_base` (the income an `income_rate` target was worked out from) and `status`: `met`, `missed`, `in_progress`, `partial` (the goal was created in the month's last 5 days) or `archived` (the goal was archived during it). A `partial` or `archived` month has no verdict and is left out of `months_met`, `months_closed`, `streak`, `best_streak` and `cumulative_difference`. A transaction tagged late recalculates the month it belongs to. `null` on a one-off goal. |
| `data[].monthly.current` | object or null |  |
| `data[].monthly.current.month` | string |  |
| `data[].monthly.current.target` | integer |  |
| `data[].monthly.current.saved` | integer |  |
| `data[].monthly.current.difference` | integer |  |
| `data[].monthly.current.status` | string |  |
| `data[].monthly.current.income_base` | integer or null |  |
| `data[].monthly.current.remaining` | integer |  |
| `data[].monthly.current.days_left` | integer |  |
| `data[].monthly.history` | array of object |  |
| `data[].monthly.history[].month` | string |  |
| `data[].monthly.history[].target` | integer |  |
| `data[].monthly.history[].saved` | integer |  |
| `data[].monthly.history[].difference` | integer |  |
| `data[].monthly.history[].status` | string |  |
| `data[].monthly.history[].income_base` | integer or null |  |
| `data[].monthly.months_met` | integer |  |
| `data[].monthly.months_closed` | integer |  |
| `data[].monthly.cumulative_difference` | integer |  |
| `data[].monthly.streak` | integer |  |
| `data[].monthly.best_streak` | integer |  |
| `data[].archived_at` | string (date-time) or null | When the goal was archived in the app, or `null`. An archived goal keeps what it saved but can no longer change. |
| `data[].created_at` | string (date-time) | When the goal was created. |
| `data[].updated_at` | string (date-time) or null | When the goal was last changed. |
| `has_more` | boolean | Whether more objects exist past this page, in the direction being walked. |

#### Example request

```bash
curl https://whisper.money/api/v1/savings-goals \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3fb-2c3d-7e4f-8a5b-6c7d8e9f0a12",
            "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "name": "Emergency fund",
            "kind": "monthly",
            "label_id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
            "currency": "EUR",
            "target_amount": null,
            "initial_amount": null,
            "target_date": null,
            "progress": null,
            "monthly_target_type": "amount",
            "monthly_target_amount": 30000,
            "monthly_target_rate": null,
            "notify_on_month_end_reminder": true,
            "auto_tag_account_id": "0199a3f3-7d2e-7c5f-8b9a-3e6d2c4b5a71",
            "monthly": {
                "current": {
                    "month": "2026-10",
                    "target": 30000,
                    "saved": 25000,
                    "difference": -5000,
                    "status": "in_progress",
                    "income_base": null,
                    "remaining": 5000,
                    "days_left": 29
                },
                "history": [
                    {
                        "month": "2026-09",
                        "target": 30000,
                        "saved": 32000,
                        "difference": 2000,
                        "status": "met",
                        "income_base": null
                    }
                ],
                "months_met": 1,
                "months_closed": 1,
                "cumulative_difference": 2000,
                "streak": 1,
                "best_streak": 1
            },
            "archived_at": null,
            "created_at": "2026-09-10T09:30:00+00:00",
            "updated_at": "2026-10-03T18:05:00+00:00"
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

## Create a savings goal

`POST /api/v1/savings-goals`

Creates the goal in the space, with a label of the same name to tag its contributions with. A one-off goal needs `target_amount`; a monthly goal needs `monthly_target_type` with its `monthly_target_amount` or `monthly_target_rate`, and its first month opens right away. The fields of the other kind are refused. A monthly goal given `auto_tag_account_id` also gets an automation rule, listed with the others, that tags the transfers into that savings account to the goal, the ones already there since the start of this month included. The rule goes last in your order, so an older rule that matches the same transfers keeps them.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | Yes | The goal's name. It also names the label its contributions are tagged with, so it must not clash with one of your labels. |
| `kind` | SavingsGoalKind | Yes | `one_off` to reach a total once, `monthly` to save towards a target every calendar month. Fixed once created. |
| `auto_tag_account_id` | string (uuid) or null | No | Monthly goals: the id of one of your savings accounts in the goal's space. A new automation rule then tags the transfers into it to this goal, from the start of this month on. An account can feed only one running monthly goal. |
| `target_amount` | integer | No | One-off goals: the total to reach, in the minor units of your account's currency. |
| `initial_amount` | integer | No | One-off goals: what is already saved towards it, in minor units. Defaults to `0`. |
| `target_date` | string (date) or null | No | One-off goals: the day to reach the total by, `YYYY-MM-DD`; in the future for a new goal. `null` clears it. |
| `monthly_target_type` | MonthlyTargetType | No | Monthly goals: `amount` for a fixed sum each month, `income_rate` for a share of the average income of the three previous complete months. Required on a new monthly goal, and on an edit alongside `monthly_target_amount` or `monthly_target_rate`; switching type clears the other value. |
| `monthly_target_amount` | integer or null | No | Monthly goals with an `amount` target: the sum to save each month, in minor units. |
| `monthly_target_rate` | number or null | No | Monthly goals with an `income_rate` target: the percentage of income to save each month, above 0 and up to 100, two decimals at most. |
| `notify_on_month_end_reminder` | boolean | No | Monthly goals: email a reminder 5 days before the month ends while the month is short of its target. A new goal defaults to your notification setting. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | SavingsGoal |  |
| `data.id` | string (uuid) | The goal's id. |
| `data.space_id` | string (uuid) or null | The id of the space the goal is in. |
| `data.name` | string | The goal's name, which is also the name of its label. |
| `data.kind` | "one_off" \| "monthly" | `one_off` to reach a total once, `monthly` to save towards a target every calendar month. Fixed once created. |
| `data.label_id` | string (uuid) or null | The id of the goal's label. A transaction counts towards the goal once it carries this label: set it with `PUT /api/v1/transactions/{id}/labels`. |
| `data.currency` | string | The currency of the goal's amounts: the currency of the account of the member who created it, as an ISO 4217 code. |
| `data.target_amount` | integer or null | One-off goals: the total to reach. `null` on a monthly goal. |
| `data.initial_amount` | integer or null | One-off goals: what was already saved when the goal was created. `null` on a monthly goal. |
| `data.target_date` | string (date) or null | One-off goals: the day to reach the total by, or `null` for none. |
| `data.progress` | object or null | One-off goals: where the goal stands. `saved` and `target` are amounts, `percentage` how much of the target is saved, `rate_per_day` what was added per day since the goal started, `status` (`completed`, `ahead`, `on_track` or `behind`, `null` without a target date) its pace against the target date, `expected_today` what that pace asks to have saved by now, `estimated_date` when the current rate reaches the target and `required_per_month` what is left to save each month to make the target date. `null` on a monthly goal. |
| `data.progress.saved` | integer |  |
| `data.progress.target` | integer |  |
| `data.progress.percentage` | number |  |
| `data.progress.target_date` | string or null |  |
| `data.progress.rate_per_day` | number |  |
| `data.progress.expected_today` | integer or null |  |
| `data.progress.status` | string or null |  |
| `data.progress.estimated_date` | string or null |  |
| `data.progress.required_per_month` | integer or null |  |
| `data.monthly_target_type` | "amount" \| "income_rate" \| null | Monthly goals: `amount` for a fixed sum each month, `income_rate` for a share of the average income of the three previous complete months, frozen when the month opens. `null` on a one-off goal. |
| `data.monthly_target_amount` | integer or null | Monthly goals with an `amount` target: the sum to save each month. `null` otherwise. |
| `data.monthly_target_rate` | number or null | Monthly goals with an `income_rate` target: the percentage of income to save each month. `null` otherwise. |
| `data.notify_on_month_end_reminder` | boolean or null | Monthly goals: whether you get an email 5 days before the month ends while the month is short of its target. `null` on a one-off goal. |
| `data.auto_tag_account_id` | string (uuid) or null | Monthly goals: the savings account whose incoming transfers an automation rule tags to this goal, or `null` for none. |
| `data.monthly` | object or null | Monthly goals: each month judged against its own target, with nothing carried over between months. `current` is the month in progress (or `null`), with what is `remaining` and the `days_left`; `history` lists every month since the goal was created, oldest first. Each month has its `target`, `saved`, signed `difference`, `income_base` (the income an `income_rate` target was worked out from) and `status`: `met`, `missed`, `in_progress`, `partial` (the goal was created in the month's last 5 days) or `archived` (the goal was archived during it). A `partial` or `archived` month has no verdict and is left out of `months_met`, `months_closed`, `streak`, `best_streak` and `cumulative_difference`. A transaction tagged late recalculates the month it belongs to. `null` on a one-off goal. |
| `data.monthly.current` | object or null |  |
| `data.monthly.current.month` | string |  |
| `data.monthly.current.target` | integer |  |
| `data.monthly.current.saved` | integer |  |
| `data.monthly.current.difference` | integer |  |
| `data.monthly.current.status` | string |  |
| `data.monthly.current.income_base` | integer or null |  |
| `data.monthly.current.remaining` | integer |  |
| `data.monthly.current.days_left` | integer |  |
| `data.monthly.history` | array of object |  |
| `data.monthly.history[].month` | string |  |
| `data.monthly.history[].target` | integer |  |
| `data.monthly.history[].saved` | integer |  |
| `data.monthly.history[].difference` | integer |  |
| `data.monthly.history[].status` | string |  |
| `data.monthly.history[].income_base` | integer or null |  |
| `data.monthly.months_met` | integer |  |
| `data.monthly.months_closed` | integer |  |
| `data.monthly.cumulative_difference` | integer |  |
| `data.monthly.streak` | integer |  |
| `data.monthly.best_streak` | integer |  |
| `data.archived_at` | string (date-time) or null | When the goal was archived in the app, or `null`. An archived goal keeps what it saved but can no longer change. |
| `data.created_at` | string (date-time) | When the goal was created. |
| `data.updated_at` | string (date-time) or null | When the goal was last changed. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/savings-goals \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Emergency fund",
    "kind": "one_off",
    "target_amount": 1000000,
    "initial_amount": 50000,
    "target_date": "2027-06-30",
    "monthly_target_amount": 30000,
    "monthly_target_rate": 15,
    "notify_on_month_end_reminder": true
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3fb-2c3d-7e4f-8a5b-6c7d8e9f0a12",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Emergency fund",
        "kind": "monthly",
        "label_id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
        "currency": "EUR",
        "target_amount": null,
        "initial_amount": null,
        "target_date": null,
        "progress": null,
        "monthly_target_type": "amount",
        "monthly_target_amount": 30000,
        "monthly_target_rate": null,
        "notify_on_month_end_reminder": true,
        "auto_tag_account_id": "0199a3f3-7d2e-7c5f-8b9a-3e6d2c4b5a71",
        "monthly": {
            "current": {
                "month": "2026-10",
                "target": 30000,
                "saved": 25000,
                "difference": -5000,
                "status": "in_progress",
                "income_base": null,
                "remaining": 5000,
                "days_left": 29
            },
            "history": [
                {
                    "month": "2026-09",
                    "target": 30000,
                    "saved": 32000,
                    "difference": 2000,
                    "status": "met",
                    "income_base": null
                }
            ],
            "months_met": 1,
            "months_closed": 1,
            "cumulative_difference": 2000,
            "streak": 1,
            "best_streak": 1
        },
        "archived_at": null,
        "created_at": "2026-09-10T09:30:00+00:00",
        "updated_at": "2026-10-03T18:05:00+00:00"
    }
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

## Retrieve a savings goal

`GET /api/v1/savings-goals/{savings_goal}`

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `savings_goal` | path | string (uuid) | Yes | The goal's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | SavingsGoal |  |
| `data.id` | string (uuid) | The goal's id. |
| `data.space_id` | string (uuid) or null | The id of the space the goal is in. |
| `data.name` | string | The goal's name, which is also the name of its label. |
| `data.kind` | "one_off" \| "monthly" | `one_off` to reach a total once, `monthly` to save towards a target every calendar month. Fixed once created. |
| `data.label_id` | string (uuid) or null | The id of the goal's label. A transaction counts towards the goal once it carries this label: set it with `PUT /api/v1/transactions/{id}/labels`. |
| `data.currency` | string | The currency of the goal's amounts: the currency of the account of the member who created it, as an ISO 4217 code. |
| `data.target_amount` | integer or null | One-off goals: the total to reach. `null` on a monthly goal. |
| `data.initial_amount` | integer or null | One-off goals: what was already saved when the goal was created. `null` on a monthly goal. |
| `data.target_date` | string (date) or null | One-off goals: the day to reach the total by, or `null` for none. |
| `data.progress` | object or null | One-off goals: where the goal stands. `saved` and `target` are amounts, `percentage` how much of the target is saved, `rate_per_day` what was added per day since the goal started, `status` (`completed`, `ahead`, `on_track` or `behind`, `null` without a target date) its pace against the target date, `expected_today` what that pace asks to have saved by now, `estimated_date` when the current rate reaches the target and `required_per_month` what is left to save each month to make the target date. `null` on a monthly goal. |
| `data.progress.saved` | integer |  |
| `data.progress.target` | integer |  |
| `data.progress.percentage` | number |  |
| `data.progress.target_date` | string or null |  |
| `data.progress.rate_per_day` | number |  |
| `data.progress.expected_today` | integer or null |  |
| `data.progress.status` | string or null |  |
| `data.progress.estimated_date` | string or null |  |
| `data.progress.required_per_month` | integer or null |  |
| `data.monthly_target_type` | "amount" \| "income_rate" \| null | Monthly goals: `amount` for a fixed sum each month, `income_rate` for a share of the average income of the three previous complete months, frozen when the month opens. `null` on a one-off goal. |
| `data.monthly_target_amount` | integer or null | Monthly goals with an `amount` target: the sum to save each month. `null` otherwise. |
| `data.monthly_target_rate` | number or null | Monthly goals with an `income_rate` target: the percentage of income to save each month. `null` otherwise. |
| `data.notify_on_month_end_reminder` | boolean or null | Monthly goals: whether you get an email 5 days before the month ends while the month is short of its target. `null` on a one-off goal. |
| `data.auto_tag_account_id` | string (uuid) or null | Monthly goals: the savings account whose incoming transfers an automation rule tags to this goal, or `null` for none. |
| `data.monthly` | object or null | Monthly goals: each month judged against its own target, with nothing carried over between months. `current` is the month in progress (or `null`), with what is `remaining` and the `days_left`; `history` lists every month since the goal was created, oldest first. Each month has its `target`, `saved`, signed `difference`, `income_base` (the income an `income_rate` target was worked out from) and `status`: `met`, `missed`, `in_progress`, `partial` (the goal was created in the month's last 5 days) or `archived` (the goal was archived during it). A `partial` or `archived` month has no verdict and is left out of `months_met`, `months_closed`, `streak`, `best_streak` and `cumulative_difference`. A transaction tagged late recalculates the month it belongs to. `null` on a one-off goal. |
| `data.monthly.current` | object or null |  |
| `data.monthly.current.month` | string |  |
| `data.monthly.current.target` | integer |  |
| `data.monthly.current.saved` | integer |  |
| `data.monthly.current.difference` | integer |  |
| `data.monthly.current.status` | string |  |
| `data.monthly.current.income_base` | integer or null |  |
| `data.monthly.current.remaining` | integer |  |
| `data.monthly.current.days_left` | integer |  |
| `data.monthly.history` | array of object |  |
| `data.monthly.history[].month` | string |  |
| `data.monthly.history[].target` | integer |  |
| `data.monthly.history[].saved` | integer |  |
| `data.monthly.history[].difference` | integer |  |
| `data.monthly.history[].status` | string |  |
| `data.monthly.history[].income_base` | integer or null |  |
| `data.monthly.months_met` | integer |  |
| `data.monthly.months_closed` | integer |  |
| `data.monthly.cumulative_difference` | integer |  |
| `data.monthly.streak` | integer |  |
| `data.monthly.best_streak` | integer |  |
| `data.archived_at` | string (date-time) or null | When the goal was archived in the app, or `null`. An archived goal keeps what it saved but can no longer change. |
| `data.created_at` | string (date-time) | When the goal was created. |
| `data.updated_at` | string (date-time) or null | When the goal was last changed. |

#### Example request

```bash
curl https://whisper.money/api/v1/savings-goals/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "id": "0199a3fb-2c3d-7e4f-8a5b-6c7d8e9f0a12",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Emergency fund",
        "kind": "monthly",
        "label_id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
        "currency": "EUR",
        "target_amount": null,
        "initial_amount": null,
        "target_date": null,
        "progress": null,
        "monthly_target_type": "amount",
        "monthly_target_amount": 30000,
        "monthly_target_rate": null,
        "notify_on_month_end_reminder": true,
        "auto_tag_account_id": "0199a3f3-7d2e-7c5f-8b9a-3e6d2c4b5a71",
        "monthly": {
            "current": {
                "month": "2026-10",
                "target": 30000,
                "saved": 25000,
                "difference": -5000,
                "status": "in_progress",
                "income_base": null,
                "remaining": 5000,
                "days_left": 29
            },
            "history": [
                {
                    "month": "2026-09",
                    "target": 30000,
                    "saved": 32000,
                    "difference": 2000,
                    "status": "met",
                    "income_base": null
                }
            ],
            "months_met": 1,
            "months_closed": 1,
            "cumulative_difference": 2000,
            "streak": 1,
            "best_streak": 1
        },
        "archived_at": null,
        "created_at": "2026-09-10T09:30:00+00:00",
        "updated_at": "2026-10-03T18:05:00+00:00"
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

## Update a savings goal

`PATCH /api/v1/savings-goals/{savings_goal}`

Changes only the fields you send, and only the fields of the goal's kind: the others, and `kind` itself, are refused. A new name renames the goal's label too. A new monthly target applies to the month in progress and later ones, never to the months already over. Only the member of the space who created the goal can change it, and an archived goal can no longer change: `422` under `savings_goal`.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `savings_goal` | path | string (uuid) | Yes | The goal's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | No | The goal's name. It also names the label its contributions are tagged with, so it must not clash with one of your labels. |
| `target_amount` | integer | No | One-off goals: the total to reach, in the minor units of your account's currency. |
| `initial_amount` | integer | No | One-off goals: what is already saved towards it, in minor units. Defaults to `0`. |
| `target_date` | string (date) or null | No | One-off goals: the day to reach the total by, `YYYY-MM-DD`; in the future for a new goal. `null` clears it. |
| `monthly_target_type` | MonthlyTargetType | No | Monthly goals: `amount` for a fixed sum each month, `income_rate` for a share of the average income of the three previous complete months. Required on a new monthly goal, and on an edit alongside `monthly_target_amount` or `monthly_target_rate`; switching type clears the other value. |
| `monthly_target_amount` | integer or null | No | Monthly goals with an `amount` target: the sum to save each month, in minor units. |
| `monthly_target_rate` | number or null | No | Monthly goals with an `income_rate` target: the percentage of income to save each month, above 0 and up to 100, two decimals at most. |
| `notify_on_month_end_reminder` | boolean | No | Monthly goals: email a reminder 5 days before the month ends while the month is short of its target. A new goal defaults to your notification setting. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | SavingsGoal |  |
| `data.id` | string (uuid) | The goal's id. |
| `data.space_id` | string (uuid) or null | The id of the space the goal is in. |
| `data.name` | string | The goal's name, which is also the name of its label. |
| `data.kind` | "one_off" \| "monthly" | `one_off` to reach a total once, `monthly` to save towards a target every calendar month. Fixed once created. |
| `data.label_id` | string (uuid) or null | The id of the goal's label. A transaction counts towards the goal once it carries this label: set it with `PUT /api/v1/transactions/{id}/labels`. |
| `data.currency` | string | The currency of the goal's amounts: the currency of the account of the member who created it, as an ISO 4217 code. |
| `data.target_amount` | integer or null | One-off goals: the total to reach. `null` on a monthly goal. |
| `data.initial_amount` | integer or null | One-off goals: what was already saved when the goal was created. `null` on a monthly goal. |
| `data.target_date` | string (date) or null | One-off goals: the day to reach the total by, or `null` for none. |
| `data.progress` | object or null | One-off goals: where the goal stands. `saved` and `target` are amounts, `percentage` how much of the target is saved, `rate_per_day` what was added per day since the goal started, `status` (`completed`, `ahead`, `on_track` or `behind`, `null` without a target date) its pace against the target date, `expected_today` what that pace asks to have saved by now, `estimated_date` when the current rate reaches the target and `required_per_month` what is left to save each month to make the target date. `null` on a monthly goal. |
| `data.progress.saved` | integer |  |
| `data.progress.target` | integer |  |
| `data.progress.percentage` | number |  |
| `data.progress.target_date` | string or null |  |
| `data.progress.rate_per_day` | number |  |
| `data.progress.expected_today` | integer or null |  |
| `data.progress.status` | string or null |  |
| `data.progress.estimated_date` | string or null |  |
| `data.progress.required_per_month` | integer or null |  |
| `data.monthly_target_type` | "amount" \| "income_rate" \| null | Monthly goals: `amount` for a fixed sum each month, `income_rate` for a share of the average income of the three previous complete months, frozen when the month opens. `null` on a one-off goal. |
| `data.monthly_target_amount` | integer or null | Monthly goals with an `amount` target: the sum to save each month. `null` otherwise. |
| `data.monthly_target_rate` | number or null | Monthly goals with an `income_rate` target: the percentage of income to save each month. `null` otherwise. |
| `data.notify_on_month_end_reminder` | boolean or null | Monthly goals: whether you get an email 5 days before the month ends while the month is short of its target. `null` on a one-off goal. |
| `data.auto_tag_account_id` | string (uuid) or null | Monthly goals: the savings account whose incoming transfers an automation rule tags to this goal, or `null` for none. |
| `data.monthly` | object or null | Monthly goals: each month judged against its own target, with nothing carried over between months. `current` is the month in progress (or `null`), with what is `remaining` and the `days_left`; `history` lists every month since the goal was created, oldest first. Each month has its `target`, `saved`, signed `difference`, `income_base` (the income an `income_rate` target was worked out from) and `status`: `met`, `missed`, `in_progress`, `partial` (the goal was created in the month's last 5 days) or `archived` (the goal was archived during it). A `partial` or `archived` month has no verdict and is left out of `months_met`, `months_closed`, `streak`, `best_streak` and `cumulative_difference`. A transaction tagged late recalculates the month it belongs to. `null` on a one-off goal. |
| `data.monthly.current` | object or null |  |
| `data.monthly.current.month` | string |  |
| `data.monthly.current.target` | integer |  |
| `data.monthly.current.saved` | integer |  |
| `data.monthly.current.difference` | integer |  |
| `data.monthly.current.status` | string |  |
| `data.monthly.current.income_base` | integer or null |  |
| `data.monthly.current.remaining` | integer |  |
| `data.monthly.current.days_left` | integer |  |
| `data.monthly.history` | array of object |  |
| `data.monthly.history[].month` | string |  |
| `data.monthly.history[].target` | integer |  |
| `data.monthly.history[].saved` | integer |  |
| `data.monthly.history[].difference` | integer |  |
| `data.monthly.history[].status` | string |  |
| `data.monthly.history[].income_base` | integer or null |  |
| `data.monthly.months_met` | integer |  |
| `data.monthly.months_closed` | integer |  |
| `data.monthly.cumulative_difference` | integer |  |
| `data.monthly.streak` | integer |  |
| `data.monthly.best_streak` | integer |  |
| `data.archived_at` | string (date-time) or null | When the goal was archived in the app, or `null`. An archived goal keeps what it saved but can no longer change. |
| `data.created_at` | string (date-time) | When the goal was created. |
| `data.updated_at` | string (date-time) or null | When the goal was last changed. |

#### Example request

```bash
curl -X PATCH https://whisper.money/api/v1/savings-goals/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Emergency fund",
    "target_amount": 1000000,
    "initial_amount": 50000,
    "target_date": "2027-06-30",
    "monthly_target_amount": 30000,
    "monthly_target_rate": 15,
    "notify_on_month_end_reminder": true
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3fb-2c3d-7e4f-8a5b-6c7d8e9f0a12",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "name": "Emergency fund",
        "kind": "monthly",
        "label_id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23",
        "currency": "EUR",
        "target_amount": null,
        "initial_amount": null,
        "target_date": null,
        "progress": null,
        "monthly_target_type": "amount",
        "monthly_target_amount": 30000,
        "monthly_target_rate": null,
        "notify_on_month_end_reminder": true,
        "auto_tag_account_id": "0199a3f3-7d2e-7c5f-8b9a-3e6d2c4b5a71",
        "monthly": {
            "current": {
                "month": "2026-10",
                "target": 30000,
                "saved": 25000,
                "difference": -5000,
                "status": "in_progress",
                "income_base": null,
                "remaining": 5000,
                "days_left": 29
            },
            "history": [
                {
                    "month": "2026-09",
                    "target": 30000,
                    "saved": 32000,
                    "difference": 2000,
                    "status": "met",
                    "income_base": null
                }
            ],
            "months_met": 1,
            "months_closed": 1,
            "cumulative_difference": 2000,
            "streak": 1,
            "best_streak": 1
        },
        "archived_at": null,
        "created_at": "2026-09-10T09:30:00+00:00",
        "updated_at": "2026-10-03T18:05:00+00:00"
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
