# Rate limits

Each user can make 60 requests a minute, across all of their tokens and connected apps.

{{TOC}}

## The limit

The limit is per user, not per token: three scripts with three tokens share the
same 60 requests a minute. Every authenticated response tells you where you
stand:

| Header                  | Meaning                              |
| ----------------------- | ------------------------------------ |
| `X-RateLimit-Limit`     | Requests allowed per minute: `60`.   |
| `X-RateLimit-Remaining` | Requests left in the current minute. |

## When you go over

Past the limit the API answers `429` with the code `too_many_requests`, plus two
more headers:

| Header              | Meaning                                     |
| ------------------- | ------------------------------------------- |
| `Retry-After`       | Seconds to wait before the next request.    |
| `X-RateLimit-Reset` | When the limit resets, as a Unix timestamp. |

Wait for `Retry-After` and try again. Requests that answered `429` did nothing,
so retrying them is safe.

## Staying under it

- Ask for full pages: `limit=100` gets the same data in a quarter of the
  requests of the default 25.
- Keep what you already fetched instead of fetching it again.
- Space your requests out rather than sending them in bursts.

The OpenAPI spec at `/api/v1/openapi.json` needs no token and does not count
against the limit.
