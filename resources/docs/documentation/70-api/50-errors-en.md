# Errors

The API answers with standard HTTP status codes, and every error body has the same shape: a message for you and a code for your program.

{{TOC}}

## The error body

```json
{
    "message": "This token is read-only. Create a read & write token to make changes.",
    "code": "insufficient_scope"
}
```

- `message` explains what went wrong. It is written for a developer and may
  change, so do not match on it.
- `code` is stable. Branch on it.

Some errors add fields of their own, listed below.

## Validation errors

A request with invalid input answers `422` with Laravel's standard body: the
first message, and every invalid field with its messages under `errors`.

```json
{
    "message": "The name field is required.",
    "errors": {
        "name": ["The name field is required."]
    }
}
```

## Codes

| Status | Code                      | When                                                                                                            |
| ------ | ------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `400`  | `invalid_cursor`          | The `starting_after` or `ending_before` id is not in the list. See [Pagination](/documentation/api/pagination). |
| `400`  | `invalid_idempotency_key` | The `Idempotency-Key` header is empty or longer than 255 characters.                                            |
| `401`  | `unauthenticated`         | No bearer token, or one that is unknown, expired or revoked.                                                    |
| `403`  | `insufficient_scope`      | The token cannot do this: a read-only token on a write, or an AI Connector token.                               |
| `403`  | `plan_required`           | The account has no paid plan. The body adds `subscribe_url`.                                                    |
| `403`  | `shared_account`          | The shared demo accounts cannot use the API.                                                                    |
| `404`  | `not_found`               | No object with that id, or the endpoint does not exist.                                                         |
| `405`  | `method_not_allowed`      | The endpoint exists, but not with that HTTP method.                                                             |
| `409`  | `idempotency_conflict`    | A request with the same `Idempotency-Key` is still running. See [Idempotency](/documentation/api/idempotency).  |
| `422`  | (none)                    | Validation failed, as shown above.                                                                              |
| `429`  | `too_many_requests`       | The rate limit is exhausted. See [Rate limits](/documentation/api/rate-limits).                                 |
| `500`  | `internal_server_error`   | Something broke on our side. Retrying later is safe; the message gives no detail.                               |

An object that belongs to someone else answers `404`, exactly like one that
does not exist.
