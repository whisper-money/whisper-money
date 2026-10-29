# API

The Whisper Money API lets your own scripts and apps read your data and change it over HTTP: accounts, transactions, categories, budgets and the rest of what you see in the app.

{{TOC}}

## What it is

A REST API that answers JSON. It reaches your own data, the same data you see
in the app, and nobody else's. It is meant for automating your finances: a
script that exports your transactions to a spreadsheet every night, a home
dashboard, or an app you build for yourself.

If what you want is to ask an assistant like Claude or ChatGPT about your money,
you do not need the API: the [AI Connector](/documentation/ai-connector) does
that without writing any code.

The API is part of the paid plan. When a subscription ends, its tokens stay but
every request answers `403` until you subscribe again.

## Base URL

Every endpoint lives under:

```text
https://whisper.money/api/v1
```

The API only speaks HTTPS and JSON. Send `Accept: application/json`, and
`Content-Type: application/json` with any request that has a body.

## Your first request

1. Open **Settings → API** and create a token. Copy it: it is shown only once.
2. List your spaces with it:

```bash
curl https://whisper.money/api/v1/spaces \
  -H "Authorization: Bearer wm_api_your_token_here"
```

The answer is your spaces, wrapped in `data`:

```json
{
    "data": [
        {
            "id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "name": "Personal",
            "personal": true,
            "owned": true,
            "created_at": "2026-01-15T09:30:00+00:00"
        }
    ]
}
```

## How it behaves

These pages describe what every endpoint has in common. Read them once before
writing a client:

- [Authentication](/documentation/api/authentication): personal tokens, OAuth
  apps and the scopes each one carries.
- [Amounts and currencies](/documentation/api/amounts-and-currencies): amounts
  are integers in each currency's smallest unit.
- [Spaces](/documentation/api/spaces): the `space` parameter.
- [Pagination](/documentation/api/pagination): how lists are paged.
- [Errors](/documentation/api/errors): the shape of every error.
- [Rate limits](/documentation/api/rate-limits): 60 requests a minute.
- [Idempotency](/documentation/api/idempotency): retrying a create safely.
- [Changelog](/documentation/api/changelog): what changed, and when.

The [API reference](/documentation/api/reference) lists every endpoint, with
its parameters, an example request and an example response.

## OpenAPI spec

The whole API is described as an OpenAPI 3.1 document at
[https://whisper.money/api/v1/openapi.json](https://whisper.money/api/v1/openapi.json).
It needs no token, so you can load it into Postman, Insomnia or a code
generator, or hand it to an AI agent that writes the client for you.
