# Changelog

Every change to the API that could affect your code, newest first.

{{TOC}}

## 2026-11-11 · Spending by label, credit card usage and this month's savings

Three figures the app's dashboard shows, for your whole account:
[`GET /api/v1/analytics/spending-by-label`](/documentation/api/reference/analytics#retrieve-spending-by-label)
ranks your labels by spending over a range against the previous period,
[`GET /api/v1/analytics/credit-card-usage`](/documentation/api/reference/analytics#retrieve-credit-card-usage)
gives how much of each credit card is in use with its day-by-day line, and
[`GET /api/v1/analytics/monthly-savings`](/documentation/api/reference/analytics#retrieve-this-months-savings)
adds up this month across your monthly savings goals and says how they did last
month. The AI Connector gets the same as the `spending_by_label`,
`get_credit_card_usage` and `get_monthly_savings` tools.

## 2026-11-11 · Aggregate transactions

[`GET /api/v1/analytics/aggregate`](/documentation/api/reference/analytics#aggregate-transactions)
groups your transactions in a space by up to two dimensions (day, week, month,
year, category, label, account or type) and returns their sum, count, average,
smallest and largest amount per group. Each currency gets its own bucket, and
grouped by a period every period of the range comes back, zeros included. The
AI Connector gets the same as the `aggregate_transactions` tool.

## 2026-10-28 · v1: initial release

The first public version of the API, under `https://whisper.money/api/v1`. It
covers everything the [AI Connector](/documentation/ai-connector) can do, and
adds an endpoint to retrieve a single account, transaction, category, label,
automation rule, budget or savings goal by its id:

- **Spaces**: list the spaces you can reach.
- **Accounts** and their **balances**: list, retrieve, create and update
  accounts; list and record balances.
- **Transactions**: search, retrieve, create, update, categorise, label, split,
  merge a split back and delete.
- **Categories** and **labels**: list, retrieve, create, update and delete.
- **Automation rules**: list, retrieve, create, update, delete, and apply one to
  the transactions already there.
- **Budgets**: list, retrieve, create, update and delete.
- **Savings goals**: list, retrieve, create and update.
- **Analytics**: cashflow, net worth and spending by category.
- **Achievements**: list your medals.

Permissions are the app's: the API reaches only your own data, even in a
[shared space](/documentation/api/spaces). Another member's account,
transaction, category, label, rule or goal answers `404`, and its id is refused
in a request body. As in the app, a transaction from your bank or an import can
be deleted and moved to another date, but its amount and names stay as the bank
sent them.

Authentication works with [personal tokens or OAuth apps](/documentation/api/authentication),
and the whole API is described in the [OpenAPI spec](https://whisper.money/api/v1/openapi.json).

## How versions will work

`/api/v1` stays as it is. We do not remove fields or endpoints from it, nor
change what they mean.

When a breaking change becomes necessary, it will ship as a dated version that
you opt into with a `Whisper-Version` header, while requests without the header
keep today's behaviour. That header is not live yet: sending it now has no
effect. Changes that cannot break a client, such as a new endpoint or a new
field in a response, are added to v1 directly and listed here.
