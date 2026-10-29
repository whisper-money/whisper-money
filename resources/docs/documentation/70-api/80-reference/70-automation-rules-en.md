# Automation rules

An automation rule gives a category, labels and a note to the transactions that meet its conditions. Rules are evaluated in `priority` order, lowest first, and a new transaction gets the actions of the first rule it matches and no other. A rule only runs on transactions created after it: run it over the ones already there with the apply endpoint.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

{{TOC}}

## List automation rules

`GET /api/v1/automation-rules`

The rules in a space in the order they are evaluated: `priority` ascending, then `title`. Every member of a space sees all of its rules.

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
| `data` | array of AutomationRule |  |
| `data[].id` | string (uuid) | The rule's id. |
| `data[].space_id` | string (uuid) or null | The id of the space the rule is in. |
| `data[].title` | string | The rule's name. |
| `data[].priority` | integer | The order the rule is evaluated in, lowest first. A new transaction gets the actions of the first rule it matches, and no other. |
| `data[].conditions` | object | The conditions a transaction must meet, as one JsonLogic object. Amounts in here are in MAJOR units, unlike every other amount in the API. A rule the AI categorization learned can still have none: `{}`. |
| `data[].action_category_id` | string (uuid) or null | The id of the category the rule gives the matching transactions, or `null` for none. |
| `data[].action_label_ids` | array of string | The ids of the labels the rule adds to the matching transactions. |
| `data[].action_note` | string or null | The note the rule appends to the matching transactions' notes, or `null` for none. |
| `data[].origin` | "user" \| "ai" \| "correction" | Who made the rule: `user` for one made by hand (in the app, the AI connector or this API), `ai` for one Whisper Money's AI categorization learned, and `correction` for one it learned from your own recategorizations. |
| `data[].owned` | boolean | Whether you created the rule. Every member of its space reads it, but only the one who created it can change, apply or delete it. |
| `data[].created_at` | string (date-time) or null | When the rule was created. |
| `data[].updated_at` | string (date-time) or null | When the rule was last changed. |
| `has_more` | boolean | Whether more objects exist past this page, in the direction being walked. |

#### Example request

```bash
curl https://whisper.money/api/v1/automation-rules \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": [
        {
            "id": "0199a3f9-5d6e-7f8a-9b0c-1d2e3f4a5b6c",
            "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "title": "Groceries",
            "priority": 10,
            "conditions": {
                "and": [
                    {
                        "in": [
                            "mercadona",
                            {
                                "var": "description"
                            }
                        ]
                    },
                    {
                        "<": [
                            {
                                "var": "amount"
                            },
                            0
                        ]
                    }
                ]
            },
            "action_category_id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
            "action_label_ids": [
                "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"
            ],
            "action_note": "Weekly shop",
            "origin": "user",
            "owned": true,
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
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`). |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Create an automation rule

`POST /api/v1/automation-rules`

The rule needs at least one action: `action_category_id`, `action_label_ids` or both. It only runs on the transactions created from now on. Conditions that say something other than what you meant still save and then quietly match nothing, so preview a new rule with the apply endpoint before trusting it.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `title` | string | Yes | The rule's name. |
| `priority` | integer | Yes | The order the rule is evaluated in, lowest first. A new transaction gets the actions of the first rule it matches, and no other. |
| `conditions` | object | Yes | The conditions a transaction must meet, as one JsonLogic object. Amounts in here are in MAJOR units (`12.50`), unlike every other amount in the API, and signed: an expense is negative. Text is compared lowercased, so write values in lowercase. Only these operators are accepted: `and`, `or`, `!`, `==`, `!=`, `>`, `>=`, `<`, `<=`, `in` and `var`; and only these variables: `description`, `notes`, `creditor_name`, `debtor_name`, `account_name`, `bank_name`, `category`, `transaction_date` (`YYYY-MM-DD`) and `amount`. "Contains" is `{"in": ["netflix", {"var": "description"}]}`, a negation wraps a condition in `!`, and `and` / `or` take a list and nest freely. "Is not" and "does not contain" also match a transaction whose field is empty. |
| `action_category_id` | string (uuid) or null | No | The id of the category to give the matching transactions, or `null` for none. A rule needs a category, labels or both. |
| `action_label_ids` | array of string (uuid) | No | The ids of the labels to add to the matching transactions, each a label of the space, 50 at most. On update the list replaces the rule's labels; an empty one removes them all. |
| `action_note` | string or null | No | A note to append to the matching transactions' notes, or `null` for none. |

#### Response

`201`

| Field | Type | Description |
| --- | --- | --- |
| `data` | AutomationRule |  |
| `data.id` | string (uuid) | The rule's id. |
| `data.space_id` | string (uuid) or null | The id of the space the rule is in. |
| `data.title` | string | The rule's name. |
| `data.priority` | integer | The order the rule is evaluated in, lowest first. A new transaction gets the actions of the first rule it matches, and no other. |
| `data.conditions` | object | The conditions a transaction must meet, as one JsonLogic object. Amounts in here are in MAJOR units, unlike every other amount in the API. A rule the AI categorization learned can still have none: `{}`. |
| `data.action_category_id` | string (uuid) or null | The id of the category the rule gives the matching transactions, or `null` for none. |
| `data.action_label_ids` | array of string | The ids of the labels the rule adds to the matching transactions. |
| `data.action_note` | string or null | The note the rule appends to the matching transactions' notes, or `null` for none. |
| `data.origin` | "user" \| "ai" \| "correction" | Who made the rule: `user` for one made by hand (in the app, the AI connector or this API), `ai` for one Whisper Money's AI categorization learned, and `correction` for one it learned from your own recategorizations. |
| `data.owned` | boolean | Whether you created the rule. Every member of its space reads it, but only the one who created it can change, apply or delete it. |
| `data.created_at` | string (date-time) or null | When the rule was created. |
| `data.updated_at` | string (date-time) or null | When the rule was last changed. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/automation-rules \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Groceries",
    "priority": 10,
    "conditions": {
        "and": [
            {
                "in": [
                    "mercadona",
                    {
                        "var": "description"
                    }
                ]
            },
            {
                "<": [
                    {
                        "var": "amount"
                    },
                    0
                ]
            }
        ]
    },
    "action_label_ids": [
        "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"
    ],
    "action_note": "Weekly shop"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f9-5d6e-7f8a-9b0c-1d2e3f4a5b6c",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "title": "Groceries",
        "priority": 10,
        "conditions": {
            "and": [
                {
                    "in": [
                        "mercadona",
                        {
                            "var": "description"
                        }
                    ]
                },
                {
                    "<": [
                        {
                            "var": "amount"
                        },
                        0
                    ]
                }
            ]
        },
        "action_category_id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
        "action_label_ids": [
            "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"
        ],
        "action_note": "Weekly shop",
        "origin": "user",
        "owned": true,
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
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`). |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Retrieve an automation rule

`GET /api/v1/automation-rules/{automation_rule}`

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `automation_rule` | path | string (uuid) | Yes | The rule's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | AutomationRule |  |
| `data.id` | string (uuid) | The rule's id. |
| `data.space_id` | string (uuid) or null | The id of the space the rule is in. |
| `data.title` | string | The rule's name. |
| `data.priority` | integer | The order the rule is evaluated in, lowest first. A new transaction gets the actions of the first rule it matches, and no other. |
| `data.conditions` | object | The conditions a transaction must meet, as one JsonLogic object. Amounts in here are in MAJOR units, unlike every other amount in the API. A rule the AI categorization learned can still have none: `{}`. |
| `data.action_category_id` | string (uuid) or null | The id of the category the rule gives the matching transactions, or `null` for none. |
| `data.action_label_ids` | array of string | The ids of the labels the rule adds to the matching transactions. |
| `data.action_note` | string or null | The note the rule appends to the matching transactions' notes, or `null` for none. |
| `data.origin` | "user" \| "ai" \| "correction" | Who made the rule: `user` for one made by hand (in the app, the AI connector or this API), `ai` for one Whisper Money's AI categorization learned, and `correction` for one it learned from your own recategorizations. |
| `data.owned` | boolean | Whether you created the rule. Every member of its space reads it, but only the one who created it can change, apply or delete it. |
| `data.created_at` | string (date-time) or null | When the rule was created. |
| `data.updated_at` | string (date-time) or null | When the rule was last changed. |

#### Example request

```bash
curl https://whisper.money/api/v1/automation-rules/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f9-5d6e-7f8a-9b0c-1d2e3f4a5b6c",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "title": "Groceries",
        "priority": 10,
        "conditions": {
            "and": [
                {
                    "in": [
                        "mercadona",
                        {
                            "var": "description"
                        }
                    ]
                },
                {
                    "<": [
                        {
                            "var": "amount"
                        },
                        0
                    ]
                }
            ]
        },
        "action_category_id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
        "action_label_ids": [
            "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"
        ],
        "action_note": "Weekly shop",
        "origin": "user",
        "owned": true,
        "created_at": "2026-01-15T09:30:00+00:00",
        "updated_at": "2026-01-20T18:05:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Update an automation rule

`PATCH /api/v1/automation-rules/{automation_rule}`

Changes only the fields you send. `conditions` and `action_label_ids` replace the rule's whole set. The rule must keep at least one action, a category or labels: leaving it without one is a `422` under `action_category_id`. Only the member of the space who created the rule can change it. The transactions it already touched keep what it gave them.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `automation_rule` | path | string (uuid) | Yes | The rule's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `title` | string | No | The rule's name. |
| `priority` | integer | No | The order the rule is evaluated in, lowest first. A new transaction gets the actions of the first rule it matches, and no other. |
| `conditions` | object | No | The conditions a transaction must meet, as one JsonLogic object. Amounts in here are in MAJOR units (`12.50`), unlike every other amount in the API, and signed: an expense is negative. Text is compared lowercased, so write values in lowercase. Only these operators are accepted: `and`, `or`, `!`, `==`, `!=`, `>`, `>=`, `<`, `<=`, `in` and `var`; and only these variables: `description`, `notes`, `creditor_name`, `debtor_name`, `account_name`, `bank_name`, `category`, `transaction_date` (`YYYY-MM-DD`) and `amount`. "Contains" is `{"in": ["netflix", {"var": "description"}]}`, a negation wraps a condition in `!`, and `and` / `or` take a list and nest freely. "Is not" and "does not contain" also match a transaction whose field is empty. |
| `action_category_id` | string (uuid) or null | No | The id of the category to give the matching transactions, or `null` for none. A rule needs a category, labels or both. |
| `action_label_ids` | array of string (uuid) | No | The ids of the labels to add to the matching transactions, each a label of the space, 50 at most. On update the list replaces the rule's labels; an empty one removes them all. |
| `action_note` | string or null | No | A note to append to the matching transactions' notes, or `null` for none. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | AutomationRule |  |
| `data.id` | string (uuid) | The rule's id. |
| `data.space_id` | string (uuid) or null | The id of the space the rule is in. |
| `data.title` | string | The rule's name. |
| `data.priority` | integer | The order the rule is evaluated in, lowest first. A new transaction gets the actions of the first rule it matches, and no other. |
| `data.conditions` | object | The conditions a transaction must meet, as one JsonLogic object. Amounts in here are in MAJOR units, unlike every other amount in the API. A rule the AI categorization learned can still have none: `{}`. |
| `data.action_category_id` | string (uuid) or null | The id of the category the rule gives the matching transactions, or `null` for none. |
| `data.action_label_ids` | array of string | The ids of the labels the rule adds to the matching transactions. |
| `data.action_note` | string or null | The note the rule appends to the matching transactions' notes, or `null` for none. |
| `data.origin` | "user" \| "ai" \| "correction" | Who made the rule: `user` for one made by hand (in the app, the AI connector or this API), `ai` for one Whisper Money's AI categorization learned, and `correction` for one it learned from your own recategorizations. |
| `data.owned` | boolean | Whether you created the rule. Every member of its space reads it, but only the one who created it can change, apply or delete it. |
| `data.created_at` | string (date-time) or null | When the rule was created. |
| `data.updated_at` | string (date-time) or null | When the rule was last changed. |

#### Example request

```bash
curl -X PATCH https://whisper.money/api/v1/automation-rules/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Groceries",
    "priority": 10,
    "conditions": {
        "and": [
            {
                "in": [
                    "mercadona",
                    {
                        "var": "description"
                    }
                ]
            },
            {
                "<": [
                    {
                        "var": "amount"
                    },
                    0
                ]
            }
        ]
    },
    "action_label_ids": [
        "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"
    ],
    "action_note": "Weekly shop"
}'
```

#### Example response

```json
{
    "data": {
        "id": "0199a3f9-5d6e-7f8a-9b0c-1d2e3f4a5b6c",
        "space_id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
        "title": "Groceries",
        "priority": 10,
        "conditions": {
            "and": [
                {
                    "in": [
                        "mercadona",
                        {
                            "var": "description"
                        }
                    ]
                },
                {
                    "<": [
                        {
                            "var": "amount"
                        },
                        0
                    ]
                }
            ]
        },
        "action_category_id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12",
        "action_label_ids": [
            "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"
        ],
        "action_note": "Weekly shop",
        "origin": "user",
        "owned": true,
        "created_at": "2026-01-15T09:30:00+00:00",
        "updated_at": "2026-01-20T18:05:00+00:00"
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Delete an automation rule

`DELETE /api/v1/automation-rules/{automation_rule}`

The rule stops running on new transactions. The transactions it already categorized or labelled keep their category and labels. Only the member of the space who created the rule can delete it.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `automation_rule` | path | string (uuid) | Yes | The rule's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `id` | string (uuid) | The deleted rule's id. |
| `deleted` | boolean | Always `true`. |

#### Example request

```bash
curl -X DELETE https://whisper.money/api/v1/automation-rules/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60 \
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
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |

## Apply an automation rule to existing transactions

`POST /api/v1/automation-rules/{automation_rule}/apply`

Runs the rule over the transactions already in the space, which it otherwise never touches. By default this is a preview (`dry_run: true`): it answers `status: preview` with how many transactions match and the first 20 of them, newest first, and changes nothing. With `dry_run: false` it **overwrites your data**: every match gets the rule's category (replacing the one it had when `only_uncategorized` is `false`), its labels added and its note appended. It cannot be undone. Up to 100 matches are applied right away and answer `200` with `status: done`; more are applied in the background over the next few minutes and answer `202` with `status: queued`. There is no progress endpoint: preview again to see what the rule still matches. Previewing and applying both need a write token, and only the member of the space who created the rule can do either. The matches are worked out once and reused for up to 15 minutes, so the apply that follows a preview goes over the transactions that preview reported; editing the rule starts over.

**Scope:** write. A personal token needs `api:write` (a read & write token); an OAuth token needs `api:use`.

#### Parameters

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `automation_rule` | path | string (uuid) | Yes | The rule's id. |
| `space` | query | string (uuid) | No | The id of the space to work in (see `GET /spaces`). Defaults to your personal space. On writes it may also be sent in the JSON body. |
| `Idempotency-Key` | header | string | No | Any unique string up to 255 characters. Retrying with the same key within 24 hours returns the first response instead of repeating the request. |

#### Request body

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `dry_run` | boolean | No | `true` (the default) only previews: it reports how many transactions the rule matches, with a sample of them, and changes nothing. Send `false` to apply the rule's actions to every match. |
| `only_uncategorized` | boolean | No | `true` (the default) skips the transactions that already have a category or, for a rule with labels and no category, that already carry all its labels. Send `false` to also take the categorized ones: the rule then overwrites the category the user or another rule gave them. Preview that combination before applying it. |

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | RuleApplication |  |
| `data.status` | "preview" \| "done" \| "queued" | `preview` when nothing was changed, `done` when the rule was applied to every match, `queued` when it is being applied in the background. |
| `data.automation_rule_id` | string (uuid) | The rule's id. |
| `data.total` | integer | How many transactions the rule matches. |
| `data.sample` | array of Transaction | On a preview, the first 20 matches, newest first; empty otherwise. |
| `data.sample[].id` | string (uuid) | The transaction's id. |
| `data.sample[].space_id` | string (uuid) or null | The id of the space the transaction is in, its account's. |
| `data.sample[].account` | object | The account the transaction is on: its id and name. |
| `data.sample[].account.id` | string |  |
| `data.sample[].account.name` | string |  |
| `data.sample[].transaction_date` | string (date) | The day the transaction happened. |
| `data.sample[].description` | string | What the transaction was for. |
| `data.sample[].amount` | integer | The signed amount, in the minor units of `currency`: negative for an expense, positive for income. |
| `data.sample[].currency` | string | The ISO 4217 currency the amount is in. |
| `data.sample[].category` | object or null | The category, if one is set: its id and name. |
| `data.sample[].category.id` | string |  |
| `data.sample[].category.name` | string |  |
| `data.sample[].category_source` | "manual" \| "rule" \| "ai" \| "bank" \| null | Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none. |
| `data.sample[].labels` | array of object | The labels the transaction is tagged with: their ids and names. |
| `data.sample[].labels[].id` | string |  |
| `data.sample[].labels[].name` | string |  |
| `data.sample[].source` | "manually_created" \| "imported" \| "enablebanking" \| "wise" | Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, date, currency, account or counterparty names, and only a manually-created one can be deleted; notes and the category can be changed on any. |
| `data.sample[].creditor_name` | string or null | Who was paid, or `null`. |
| `data.sample[].debtor_name` | string or null | Who paid, or `null`. |
| `data.sample[].notes` | string or null | Free-text notes, or `null`. |
| `data.sample[].split_parent_id` | string (uuid) or null | Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own. |
| `data.sample[].created_at` | string (date-time) or null | When the transaction was recorded in Whisper Money. |
| `data.applied` | integer or null | When `done`, how many matches the rule went over; `null` otherwise. |
| `data.updated` | integer or null | When `done`, how many of them it changed: one that already had the rule's category, labels and note is applied but not updated. `null` otherwise. |

#### Example request

```bash
curl -X POST https://whisper.money/api/v1/automation-rules/0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60/apply \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "dry_run": true
}'
```

#### Example response

```json
{
    "data": {
        "status": "preview",
        "automation_rule_id": "0199a3f9-5d6e-7f8a-9b0c-1d2e3f4a5b6c",
        "total": 42,
        "sample": [
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
        "applied": null,
        "updated": null
    }
}
```

#### Errors

| Status | When |
| --- | --- |
| `400` | A malformed request: an unknown pagination cursor (`invalid_cursor`) or an invalid `Idempotency-Key` (`invalid_idempotency_key`). |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`), it is a shared demo account (`shared_account`) or the object belongs to another member of the space (`not_account_owner`, `not_transaction_owner`, `not_category_owner`, `not_label_owner`, `not_automation_rule_owner`). |
| `404` | No object with that id exists, or it belongs to someone else. |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | The request failed validation. |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |
