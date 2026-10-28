# API reference

Every endpoint of the Whisper Money API, grouped by resource. The base URL is `https://whisper.money/api/v1`, and the full OpenAPI spec is at [https://whisper.money/api/v1/openapi.json](https://whisper.money/api/v1/openapi.json).

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

Read [Authentication](/documentation/api/authentication) first, and [Errors](/documentation/api/errors) for what any endpoint can answer besides its own response.

## Spaces

A space holds a set of accounts and everything recorded on them. Every user has a personal space and may be invited to others. Endpoints that work on one space take an optional `space` parameter, which defaults to the personal space.

| Endpoint | What it does |
| --- | --- |
| [`GET /api/v1/spaces`](/documentation/api/reference/spaces#list-spaces) | List spaces |

## Accounts

An account is a bookkeeping record of money held somewhere: a manual one you keep up to date yourself, or a bank-connected one the bank sync keeps up to date. Nothing here moves money; that happens at the bank.

| Endpoint | What it does |
| --- | --- |
| [`GET /api/v1/accounts`](/documentation/api/reference/accounts#list-accounts) | List accounts |
| [`POST /api/v1/accounts`](/documentation/api/reference/accounts#create-an-account) | Create an account |
| [`GET /api/v1/accounts/{account}`](/documentation/api/reference/accounts#retrieve-an-account) | Retrieve an account |
| [`PATCH /api/v1/accounts/{account}`](/documentation/api/reference/accounts#update-an-account) | Update an account |

## Balances

A balance is a snapshot of what an account held at the end of one day, in the account currency's minor units. A bank-connected account gets its balances from the bank sync; you record those of a manual account yourself. A credit card has no balance: it has a credit limit, and what is in use of it comes from its transactions.

| Endpoint | What it does |
| --- | --- |
| [`GET /api/v1/accounts/{account}/balances`](/documentation/api/reference/balances#list-an-accounts-balances) | List an account's balances |
| [`POST /api/v1/accounts/{account}/balances`](/documentation/api/reference/balances#record-a-balance) | Record a balance |

## Transactions

A transaction is money that moved in or out of an account: synced from the bank, imported from a file or recorded by hand. Whisper Money only keeps the books: recording a transaction moves no money. Amounts are signed, in the minor units of the transaction's own currency: negative for an expense, positive for income.

| Endpoint | What it does |
| --- | --- |
| [`GET /api/v1/transactions`](/documentation/api/reference/transactions#list-transactions) | List transactions |
| [`POST /api/v1/transactions`](/documentation/api/reference/transactions#create-a-transaction) | Create a transaction |
| [`GET /api/v1/transactions/{transaction}`](/documentation/api/reference/transactions#retrieve-a-transaction) | Retrieve a transaction |
| [`PATCH /api/v1/transactions/{transaction}`](/documentation/api/reference/transactions#update-a-transaction) | Update a transaction |
| [`DELETE /api/v1/transactions/{transaction}`](/documentation/api/reference/transactions#delete-a-transaction) | Delete a transaction |
| [`PUT /api/v1/transactions/{transaction}/labels`](/documentation/api/reference/transactions#replace-the-labels-of-a-transaction) | Replace the labels of a transaction |
| [`POST /api/v1/transactions/{transaction}/split`](/documentation/api/reference/transactions#split-a-transaction) | Split a transaction |
| [`POST /api/v1/transactions/{transaction}/merge-splits`](/documentation/api/reference/transactions#merge-a-split-back) | Merge a split back |

## Categories

A category says what a transaction was for. Each transaction has at most one. Categories form a tree up to 3 levels deep through `parent_id`, and a whole tree shares its root's type and cashflow direction. A budget that tracks a parent category also tracks its children.

| Endpoint | What it does |
| --- | --- |
| [`GET /api/v1/categories`](/documentation/api/reference/categories#list-categories) | List categories |
| [`POST /api/v1/categories`](/documentation/api/reference/categories#create-a-category) | Create a category |
| [`GET /api/v1/categories/{category}`](/documentation/api/reference/categories#retrieve-a-category) | Retrieve a category |
| [`PATCH /api/v1/categories/{category}`](/documentation/api/reference/categories#update-a-category) | Update a category |
| [`DELETE /api/v1/categories/{category}`](/documentation/api/reference/categories#delete-a-category) | Delete a category |

## Labels

A label is a free tag: a transaction can carry any number of them next to its single category. Labels are also how a savings goal counts its contributions, and budgets and automation rules can use them. Names are unique within a space.

| Endpoint | What it does |
| --- | --- |
| [`GET /api/v1/labels`](/documentation/api/reference/labels#list-labels) | List labels |
| [`POST /api/v1/labels`](/documentation/api/reference/labels#create-a-label) | Create a label |
| [`GET /api/v1/labels/{label}`](/documentation/api/reference/labels#retrieve-a-label) | Retrieve a label |
| [`PATCH /api/v1/labels/{label}`](/documentation/api/reference/labels#update-a-label) | Update a label |
| [`DELETE /api/v1/labels/{label}`](/documentation/api/reference/labels#delete-a-label) | Delete a label |

## Automation rules

An automation rule gives a category, labels and a note to the transactions that meet its conditions. Rules are evaluated in `priority` order, lowest first, and a new transaction gets the actions of the first rule it matches and no other. A rule only runs on transactions created after it: run it over the ones already there with the apply endpoint.

| Endpoint | What it does |
| --- | --- |
| [`GET /api/v1/automation-rules`](/documentation/api/reference/automation-rules#list-automation-rules) | List automation rules |
| [`POST /api/v1/automation-rules`](/documentation/api/reference/automation-rules#create-an-automation-rule) | Create an automation rule |
| [`GET /api/v1/automation-rules/{automation_rule}`](/documentation/api/reference/automation-rules#retrieve-an-automation-rule) | Retrieve an automation rule |
| [`PATCH /api/v1/automation-rules/{automation_rule}`](/documentation/api/reference/automation-rules#update-an-automation-rule) | Update an automation rule |
| [`DELETE /api/v1/automation-rules/{automation_rule}`](/documentation/api/reference/automation-rules#delete-an-automation-rule) | Delete an automation rule |
| [`POST /api/v1/automation-rules/{automation_rule}/apply`](/documentation/api/reference/automation-rules#apply-an-automation-rule-to-existing-transactions) | Apply an automation rule to existing transactions |
