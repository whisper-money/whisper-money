# Changelog

Every change to the API that could affect your code, newest first.

{{TOC}}

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
