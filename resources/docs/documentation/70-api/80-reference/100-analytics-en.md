# Analytics

Figures rolled up from your transactions and balances over a date range: cashflow, net worth and spending by category. They cover your whole account, not a space, and every amount is in the minor units of your account's currency, given as `currency`.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

{{TOC}}

## Retrieve the cashflow

`GET /api/v1/analytics/cashflow`

The cashflow of a date range: income, expenses, savings and investments against the previous period, your expenses split by the budgets that track them (fixed, variable and general), what your budgets still reserve, where the money came from and went by category, and the monthly trend. The range can touch at most 24 calendar months.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `from` | query | string (date) | Yes | The first day of the range, inclusive. |
| `to` | query | string (date) | Yes | The last day of the range, inclusive. Not before `from`. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | Cashflow |  |
| `data.currency` | string | The currency of every amount: your account's currency, as an ISO 4217 code. |
| `data.summary` | object |  |
| `data.summary.current` | CashflowPeriod | The range's cashflow. |
| `data.summary.current.income` | integer | What came in: the transactions in income categories, and the uncategorized ones that brought money in. |
| `data.summary.current.expense` | integer | What went out: the transactions in expense categories, and the uncategorized ones that took money out. Savings, investments and transfers are not in it. |
| `data.summary.current.net` | integer | `income` minus `expense`. |
| `data.summary.current.savings_rate` | number | `net` as a percentage of `income`, rounded to one decimal and never above 100. `0` with no income. |
| `data.summary.current.savings` | integer | What was set aside into savings categories, net of withdrawals. |
| `data.summary.current.investments` | integer | What was set aside into investment categories, net of withdrawals. |
| `data.summary.current.fixed_expense` | integer | The part of `expense` tracked by budgets marked fixed (rent, subscriptions). Budgets are variable unless marked fixed, so `0` may only mean none has been marked yet. A transaction tracked by a fixed and a variable budget counts here, once. |
| `data.summary.current.variable_expense` | integer | The part of `expense` tracked by budgets marked variable (groceries, leisure). Catch-all budgets do not count. |
| `data.summary.current.general_expense` | integer | The rest of `expense`, which no budget tracks. The three parts add up to `expense`, and any can be negative in a period of refunds. |
| `data.summary.current.reserved` | integer | Current period only: what the budgets in `budgets` still hold back for spending, adding up their positive margins. An over-budget period adds nothing. |
| `data.summary.current.budgets` | array of CashflowBudget | Current period only: the budget periods in progress today that overlap the range. A range of up to 31 days counts weekly, bi-weekly and monthly budgets, one of up to 92 days those plus quarterly ones, and a longer one only yearly budgets. |
| `data.summary.current.budgets[].budget_id` | string (uuid) | The budget's id. |
| `data.summary.current.budgets[].budget_period_id` | string (uuid) | The id of the budget's period in progress. |
| `data.summary.current.budgets[].name` | string | The budget's name. |
| `data.summary.current.budgets[].start_date` | string (date) | The period's first day. |
| `data.summary.current.budgets[].end_date` | string (date) | The period's last day, included. |
| `data.summary.current.budgets[].expense_type` | "fixed" \| "variable" | `fixed` or `variable`: which part of the expense split the budget's spending counts in. |
| `data.summary.current.budgets[].allocated` | integer | The period's limit, in minor units of `currency`. |
| `data.summary.current.budgets[].spent` | integer | What was spent against it so far. |
| `data.summary.current.budgets[].margin` | integer | `allocated` minus `spent`, negative once the period goes over its limit. Like the budgets endpoints, it leaves out any carried-over amount. |
| `data.summary.previous` | CashflowPeriod | The cashflow of the period before it, to compare with: the same number of days right before `from`, or the same number of whole months when the range is whole months. It has the expense split but no `reserved` or `budgets`, which are about today. |
| `data.summary.previous.income` | integer | What came in: the transactions in income categories, and the uncategorized ones that brought money in. |
| `data.summary.previous.expense` | integer | What went out: the transactions in expense categories, and the uncategorized ones that took money out. Savings, investments and transfers are not in it. |
| `data.summary.previous.net` | integer | `income` minus `expense`. |
| `data.summary.previous.savings_rate` | number | `net` as a percentage of `income`, rounded to one decimal and never above 100. `0` with no income. |
| `data.summary.previous.savings` | integer | What was set aside into savings categories, net of withdrawals. |
| `data.summary.previous.investments` | integer | What was set aside into investment categories, net of withdrawals. |
| `data.summary.previous.fixed_expense` | integer | The part of `expense` tracked by budgets marked fixed (rent, subscriptions). Budgets are variable unless marked fixed, so `0` may only mean none has been marked yet. A transaction tracked by a fixed and a variable budget counts here, once. |
| `data.summary.previous.variable_expense` | integer | The part of `expense` tracked by budgets marked variable (groceries, leisure). Catch-all budgets do not count. |
| `data.summary.previous.general_expense` | integer | The rest of `expense`, which no budget tracks. The three parts add up to `expense`, and any can be negative in a period of refunds. |
| `data.summary.previous.reserved` | integer | Current period only: what the budgets in `budgets` still hold back for spending, adding up their positive margins. An over-budget period adds nothing. |
| `data.summary.previous.budgets` | array of CashflowBudget | Current period only: the budget periods in progress today that overlap the range. A range of up to 31 days counts weekly, bi-weekly and monthly budgets, one of up to 92 days those plus quarterly ones, and a longer one only yearly budgets. |
| `data.summary.previous.budgets[].budget_id` | string (uuid) | The budget's id. |
| `data.summary.previous.budgets[].budget_period_id` | string (uuid) | The id of the budget's period in progress. |
| `data.summary.previous.budgets[].name` | string | The budget's name. |
| `data.summary.previous.budgets[].start_date` | string (date) | The period's first day. |
| `data.summary.previous.budgets[].end_date` | string (date) | The period's last day, included. |
| `data.summary.previous.budgets[].expense_type` | "fixed" \| "variable" | `fixed` or `variable`: which part of the expense split the budget's spending counts in. |
| `data.summary.previous.budgets[].allocated` | integer | The period's limit, in minor units of `currency`. |
| `data.summary.previous.budgets[].spent` | integer | What was spent against it so far. |
| `data.summary.previous.budgets[].margin` | integer | `allocated` minus `spent`, negative once the period goes over its limit. Like the budgets endpoints, it leaves out any carried-over amount. |
| `data.sankey` | object |  |
| `data.sankey.income_categories` | array of CategoryAmount | Where the money came from, by top-level category. A category is netted and lands on the side its net points at, so an expense category that got more back than it spent is listed here as a refund. Savings and investment withdrawals and transfers set to count as an inflow appear here too, and the uncategorized money that came in is one row with no `category_id`. |
| `data.sankey.income_categories[].category_id` | string (uuid) or null | The category's id, or `null` for the row that gathers the transactions with no category. |
| `data.sankey.income_categories[].name` | string or null | The category's name. The row with no category is `Unknown Income` or `Unknown Expense`. On the cashflow's top level, a category whose net crossed to the other side says so: `Groceries (refund)`, `Savings (withdrawal)`, `Salary (reversal)`. |
| `data.sankey.income_categories[].type` | string or null | The category's type: `income`, `expense`, `savings`, `investment` or `transfer`. |
| `data.sankey.income_categories[].color` | string or null | The category's colour in the app. |
| `data.sankey.income_categories[].icon` | string or null | The category's icon in the app, a Lucide icon name. |
| `data.sankey.income_categories[].amount` | integer | What the category adds up to over the period, as a positive amount in minor units of `currency`. |
| `data.sankey.income_categories[].has_children` | boolean | Whether the category has subcategories to drill into. |
| `data.sankey.income_categories[].is_direct` | boolean | When drilling into a category, `true` on the row for the transactions booked to that category itself rather than to one of its children. |
| `data.sankey.expense_categories` | array of CategoryAmount | Where the money went, by top-level category, built the same way. Savings, investments and transfers set to count as an outflow appear here too. |
| `data.sankey.expense_categories[].category_id` | string (uuid) or null | The category's id, or `null` for the row that gathers the transactions with no category. |
| `data.sankey.expense_categories[].name` | string or null | The category's name. The row with no category is `Unknown Income` or `Unknown Expense`. On the cashflow's top level, a category whose net crossed to the other side says so: `Groceries (refund)`, `Savings (withdrawal)`, `Salary (reversal)`. |
| `data.sankey.expense_categories[].type` | string or null | The category's type: `income`, `expense`, `savings`, `investment` or `transfer`. |
| `data.sankey.expense_categories[].color` | string or null | The category's colour in the app. |
| `data.sankey.expense_categories[].icon` | string or null | The category's icon in the app, a Lucide icon name. |
| `data.sankey.expense_categories[].amount` | integer | What the category adds up to over the period, as a positive amount in minor units of `currency`. |
| `data.sankey.expense_categories[].has_children` | boolean | Whether the category has subcategories to drill into. |
| `data.sankey.expense_categories[].is_direct` | boolean | When drilling into a category, `true` on the row for the transactions booked to that category itself rather than to one of its children. |
| `data.sankey.total_income` | integer | What `income_categories` adds up to. |
| `data.sankey.total_expense` | integer | What `expense_categories` adds up to. Savings, investments and outflow transfers are in it, so it is not `summary.current.expense`. |
| `data.trend` | array of object | Income, expense and net per calendar month the range touches, oldest first. Counted like `current`: savings, investments and transfers left out. |
| `data.trend[].month` | string |  |
| `data.trend[].income` | integer |  |
| `data.trend[].expense` | integer |  |
| `data.trend[].net` | integer |  |

#### Example request

```bash
curl "https://whisper.money/api/v1/analytics/cashflow?from=2026-10-01&to=2026-10-31" \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "currency": "EUR",
        "summary": {
            "current": {
                "income": 260000,
                "expense": 96122,
                "net": 163878,
                "savings_rate": 63,
                "savings": 10000,
                "investments": 0,
                "fixed_expense": 90000,
                "variable_expense": 5345,
                "general_expense": 777,
                "reserved": 17950,
                "budgets": [
                    {
                        "budget_id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
                        "budget_period_id": "0199a3fa-1b2c-7d3e-8f4a-5b6c7d8e9f01",
                        "name": "Groceries",
                        "start_date": "2026-10-01",
                        "end_date": "2026-10-31",
                        "expense_type": "variable",
                        "allocated": 50000,
                        "spent": 32050,
                        "margin": 17950
                    }
                ]
            },
            "previous": {
                "income": 260000,
                "expense": 96122,
                "net": 163878,
                "savings_rate": 63,
                "savings": 10000,
                "investments": 0,
                "fixed_expense": 90000,
                "variable_expense": 5345,
                "general_expense": 777,
                "reserved": 17950,
                "budgets": [
                    {
                        "budget_id": "0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b",
                        "budget_period_id": "0199a3fa-1b2c-7d3e-8f4a-5b6c7d8e9f01",
                        "name": "Groceries",
                        "start_date": "2026-10-01",
                        "end_date": "2026-10-31",
                        "expense_type": "variable",
                        "allocated": 50000,
                        "spent": 32050,
                        "margin": 17950
                    }
                ]
            }
        },
        "sankey": {
            "income_categories": [
                {
                    "category_id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
                    "name": "Groceries",
                    "type": "expense",
                    "color": "orange",
                    "icon": "ShoppingCart",
                    "amount": 15345,
                    "has_children": false,
                    "is_direct": false
                }
            ],
            "expense_categories": [
                {
                    "category_id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
                    "name": "Groceries",
                    "type": "expense",
                    "color": "orange",
                    "icon": "ShoppingCart",
                    "amount": 15345,
                    "has_children": false,
                    "is_direct": false
                }
            ],
            "total_income": 260000,
            "total_expense": 106122
        },
        "trend": [
            {
                "month": "2026-10",
                "income": 260000,
                "expense": 96122,
                "net": 163878
            }
        ]
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `422` | Validation error |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Retrieve the net worth

`GET /api/v1/analytics/net-worth`

Your net worth at the end of a date range against the end of the previous period, and every account's balance over the range, monthly or daily. A monthly range can touch at most 120 calendar months, a daily one cover at most 366 days.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `from` | query | string (date) | Yes | The first day of the range, inclusive. |
| `to` | query | string (date) | Yes | The last day of the range, inclusive. Not before `from`. |
| `granularity` | query | "monthly" \| "daily" | No | `monthly` (the default) for a point at the end of each month of the range, `daily` for one per day. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | NetWorth |  |
| `data.currency` | string | The currency of every amount: your account's currency, as an ISO 4217 code. Balances in another currency are converted at each day's exchange rate. |
| `data.granularity` | "monthly" \| "daily" | `monthly` or `daily`, as requested. |
| `data.net_worth` | integer | Your net worth on `to`: the balances of the accounts with `counts_in_net_worth`, converted to `currency`, a loan's debt counting against it. Credit cards are never part of it, and loans and real estate only when your net worth settings include them. |
| `data.previous_net_worth` | integer | Your net worth at the end of the previous period: the same number of days right before `from`, or the same number of whole months when the range is whole months. |
| `data.accounts` | array of object | Every account the evolution covers, archived ones and the ones left out of `net_worth` included: its id, name, type, own currency, and whether it is part of `net_worth`. |
| `data.accounts[].id` | string |  |
| `data.accounts[].name` | string |  |
| `data.accounts[].type` | string |  |
| `data.accounts[].currency` | string |  |
| `data.accounts[].counts_in_net_worth` | boolean |  |
| `data.evolution` | array of NetWorthPoint | One point per month the range touches, at the month's last day even when `to` falls before it, or one per day of the range; oldest first, with each account's balance on it. |
| `data.evolution[].period` | string | The month (`2026-10`) or, with `daily` granularity, the day (`2026-10-15`). |
| `data.evolution[].balances` | array of object | One entry per account in `accounts`, in the same order: `balance` in `currency`, a loan's as the positive amount owed; `original_balance` and `original_currency` the account's own figure when its currency is another one, `null` otherwise; `invested_amount` what was put into an investment or retirement account on a monthly point, `null` for other types, on daily points or when not recorded. |
| `data.evolution[].balances[].account_id` | string |  |
| `data.evolution[].balances[].balance` | integer |  |
| `data.evolution[].balances[].original_balance` | integer or null |  |
| `data.evolution[].balances[].original_currency` | string or null |  |
| `data.evolution[].balances[].invested_amount` | integer or null |  |

#### Example request

```bash
curl "https://whisper.money/api/v1/analytics/net-worth?from=2026-10-01&to=2026-10-31" \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "currency": "EUR",
        "granularity": "monthly",
        "net_worth": 366364,
        "previous_net_worth": 360000,
        "accounts": [
            {
                "id": "0199a3f2-8b1c-7d4e-9f5a-1b2c3d4e5f60",
                "name": "Main checking",
                "type": "checking",
                "currency": "EUR",
                "counts_in_net_worth": true
            }
        ],
        "evolution": [
            {
                "period": "2026-10",
                "balances": [
                    {
                        "account_id": "0199a3f2-8b1c-7d4e-9f5a-1b2c3d4e5f60",
                        "balance": 130000,
                        "original_balance": null,
                        "original_currency": null,
                        "invested_amount": null
                    }
                ]
            }
        ]
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `422` | Validation error |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Retrieve spending by category

`GET /api/v1/analytics/spending-by-category`

Your expenses in a date range by top-level category, each with its subcategories rolled into it. Pass `parent_category_id` to drill into a category: its children come back instead, plus a row for what was booked to the category itself (`is_direct`). The range can touch at most 120 calendar months.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `from` | query | string (date) | Yes | The first day of the range, inclusive. |
| `to` | query | string (date) | Yes | The last day of the range, inclusive. Not before `from`. |
| `parent_category_id` | query | string (uuid) or null | No | Drill into one of your categories: its children are returned instead of the top-level categories. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | SpendingByCategory |  |
| `data.currency` | string | The currency of every amount: your account's currency, as an ISO 4217 code. |
| `data.total` | integer | What `categories` adds up to. |
| `data.categories` | array of CategoryAmount | The expense categories with spending in the range, largest first, each with its subcategories rolled into it. Only the ones whose spending is above zero: a category that refunds took below zero is left out, and so are uncategorized transactions, so `total` can be above the cashflow's `expense` for the same range. |
| `data.categories[].category_id` | string (uuid) or null | The category's id, or `null` for the row that gathers the transactions with no category. |
| `data.categories[].name` | string or null | The category's name. The row with no category is `Unknown Income` or `Unknown Expense`. On the cashflow's top level, a category whose net crossed to the other side says so: `Groceries (refund)`, `Savings (withdrawal)`, `Salary (reversal)`. |
| `data.categories[].type` | string or null | The category's type: `income`, `expense`, `savings`, `investment` or `transfer`. |
| `data.categories[].color` | string or null | The category's colour in the app. |
| `data.categories[].icon` | string or null | The category's icon in the app, a Lucide icon name. |
| `data.categories[].amount` | integer | What the category adds up to over the period, as a positive amount in minor units of `currency`. |
| `data.categories[].has_children` | boolean | Whether the category has subcategories to drill into. |
| `data.categories[].is_direct` | boolean | When drilling into a category, `true` on the row for the transactions booked to that category itself rather than to one of its children. |

#### Example request

```bash
curl "https://whisper.money/api/v1/analytics/spending-by-category?from=2026-10-01&to=2026-10-31" \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "currency": "EUR",
        "total": 105345,
        "categories": [
            {
                "category_id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
                "name": "Groceries",
                "type": "expense",
                "color": "orange",
                "icon": "ShoppingCart",
                "amount": 15345,
                "has_children": false,
                "is_direct": false
            }
        ]
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `422` | Validation error |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |
