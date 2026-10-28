# Spaces

A space holds a set of accounts and everything recorded on them. Every user has a personal space and may be invited to others. Endpoints that work on one space take an optional `space` parameter, which defaults to the personal space.

<!-- Generated from the OpenAPI spec by `php artisan api:docs`. Do not edit by hand. -->

The example requests read your personal token from `$WHISPER_MONEY_TOKEN`: run `export WHISPER_MONEY_TOKEN=wm_api_your_token_here` before pasting them.

{{TOC}}

## List spaces

`GET /api/v1/spaces`

Every space you can access, your personal space first. You belong to a handful of spaces at most, so the list is returned whole, without pagination.

**Scope:** read. A personal token needs `api:read`; an OAuth token needs `api:use`.

#### Response

`200`

| Field | Type | Description |
| --- | --- | --- |
| `data` | array of Space |  |
| `data[].id` | string (uuid) | The space's id, which the `space` parameter takes. |
| `data[].name` | string | The space's name. |
| `data[].personal` | boolean | Whether this is your personal space, the default when `space` is left out. |
| `data[].owned` | boolean | Whether you own the space, rather than being a member of it. |
| `data[].created_at` | string (date-time) or null | When the space was created. |

#### Example request

```bash
curl https://whisper.money/api/v1/spaces \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

#### Example response

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

#### Errors

| Status | When |
| --- | --- |
| `401` | No bearer token, or one that is unknown, expired or revoked. |
| `403` | The token lacks the scope (`insufficient_scope`), the account has no Pro plan (`plan_required`, with a `subscribe_url`) or it is a shared demo account (`shared_account`). |
| `429` | The rate limit is exhausted. Wait the seconds in `Retry-After`. |
