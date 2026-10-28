# Spaces

Your data is organised in spaces: your personal space and any space someone shared with you. Endpoints that work on one take an optional `space` parameter.

{{TOC}}

## What a space is

A space holds a set of accounts and everything recorded on them: transactions,
categories, labels, budgets. Everyone has a personal space, and a space can be
shared with other people, who then see and edit everything in it.

List the spaces you can reach, with their ids:

```bash
curl https://whisper.money/api/v1/spaces \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

## The `space` parameter

Endpoints that read or write data inside a space accept an optional `space`
parameter with a space id. Leave it out and the endpoint works on your personal
space.

On a `GET`, send it in the query string:

```text
GET /api/v1/accounts?space=0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
```

On a `POST` or `PATCH`, send it in the query string or in the JSON body.

An id you have no access to answers `422`, with the error under `space`. The
[reference](/documentation/api/reference) lists `space` among the parameters of
every endpoint that takes it.

Analytics cover your whole account rather than one space, as they do in the app.
