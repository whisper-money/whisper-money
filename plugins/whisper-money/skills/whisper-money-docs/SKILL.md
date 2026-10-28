---
name: whisper-money-docs
description: Read Whisper Money's product documentation before answering how the app works — how accounts, transactions, imports, categories, labels, automation rules, cashflow or budgets behave, what a feature does or does not do, how to connect an AI assistant to it, how to use its REST API, pricing, privacy and which banks connect. Use when the user asks a "how does Whisper Money…" or "can Whisper Money…" question instead of answering from memory.
---

# Whisper Money documentation

Every page is served as Markdown by appending `.md` to its URL. Fetch that
version — it is the same content without the page chrome.

## Where to start

`https://whisper.money/documentation.md` is the full index: every page with its
description, nested under the topic it belongs to. Read it first when you are
not sure which page answers the question, then fetch the page it points at.

Individual pages follow `https://whisper.money/documentation/{slug}.md`:

| Topic | Slug |
| --- | --- |
| Getting started, onboarding, where things are in the app | `getting-started` |
| Accounts, connecting a bank | `accounts` |
| Importing and editing balances | `accounts/import-balances`, `accounts/edit-balances` |
| Credit cards, statements, limit and usage | `accounts/credit-cards` |
| Bank and broker connections, syncing, consent renewal | `accounts/bank-connections` |
| Transactions | `transactions` |
| Creating and importing transactions | `transactions/create`, `transactions/import` |
| Splitting a transaction | `transactions/split` |
| Duplicating a transaction | `transactions/duplicate` |
| Importing everything from another finance app | `import-from-another-app` |
| Categories | `categories` |
| Labels | `labels` |
| Automation rules | `automation-rules` |
| Dashboard, net worth chart, account cards, privacy mode | `dashboard` |
| Cashflow, available and free money, fixed and variable expenses | `cashflow` |
| Budgets, fixed or variable expense type, reserved money | `budgets` |
| Savings goals, one-off and monthly | `savings-goals` |
| Achievements, medals, visit streak | `achievements` |
| Monthly summary, report email | `monthly-summary` |
| AI Connector, MCP, connecting Claude or ChatGPT | `ai-connector` |
| REST API: overview, base URL, first request | `api` |
| API tokens, OAuth apps, scopes | `api/authentication` |
| API amounts, spaces, pagination, errors, rate limits, idempotency | `api/amounts-and-currencies`, `api/spaces`, `api/pagination`, `api/errors`, `api/rate-limits`, `api/idempotency` |
| API endpoint reference (English only) | `api/reference` |

Add `?lang=es` for Spanish: `https://whisper.money/documentation/budgets.md?lang=es`.

The API is also described as an OpenAPI 3.1 document at
`https://whisper.money/api/v1/openapi.json`. Read it when you need exact
request or response fields.

## Anything else

`https://whisper.money/llms.txt` indexes everything public beyond the docs — the
product summary, roadmap, privacy policy, terms, the banks and apps that can be
connected, and the comparison pages.

## How to answer

- Read the page before answering. These pages change; memory does not.
- Answer from what the page says, and link it (the human URL, without `.md`) so
  the user can read the rest.
- If the docs do not cover it, say so rather than inferring. The roadmap page
  is where "not yet" lives.
