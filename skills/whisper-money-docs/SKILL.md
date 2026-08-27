---
name: whisper-money-docs
description: Read Whisper Money's product documentation before answering how the app works — how accounts, transactions, imports, categories, labels, automation rules, cashflow or budgets behave, what a feature does or does not do, pricing, privacy and which banks connect. Use when the user asks a "how does Whisper Money…" or "can Whisper Money…" question instead of answering from memory.
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
| Getting started | `getting-started` |
| Accounts, connecting a bank | `accounts` |
| Importing and editing balances | `accounts/import-balances`, `accounts/edit-balances` |
| Transactions | `transactions` |
| Creating and importing transactions | `transactions/create`, `transactions/import` |
| Splitting a transaction | `transactions/split` |
| Categories | `categories` |
| Labels | `labels` |
| Automation rules | `automation-rules` |
| Cashflow | `cashflow` |
| Budgets | `budgets` |
| Savings goals | `savings-goals` |

Add `?lang=es` for Spanish: `https://whisper.money/documentation/budgets.md?lang=es`.

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
