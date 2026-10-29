# API reference

Every endpoint of the Whisper Money API, grouped by resource. The base URL is `https://whisper.money/api/v1`, and the full OpenAPI spec is at [https://whisper.money/api/v1/openapi.json](https://whisper.money/api/v1/openapi.json).

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

Read [Authentication](/documentation/api/authentication) first, and [Errors](/documentation/api/errors) for what any endpoint can answer besides its own response.

## Spaces

A space holds a set of accounts and everything recorded on them. Every user has a personal space and may be invited to others. Endpoints that work on one space take an optional `space` parameter, which defaults to the personal space.

| Endpoint | What it does |
| --- | --- |
| [`GET /api/v1/spaces`](/documentation/api/reference/spaces#list-spaces) | List spaces |
