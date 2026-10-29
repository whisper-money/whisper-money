# Idempotency

Send an `Idempotency-Key` header with a `POST` and you can retry it after a timeout without creating the same thing twice.

{{TOC}}

## Why it matters

When a request to create something times out, you cannot tell whether it
failed or whether only the answer got lost. Retrying blindly could record the
same transaction twice. An idempotency key makes the retry safe: the API
remembers the first answer and repeats it.

## How to use it

Generate a unique value for each operation, a UUID for instance, and send it as
a header. Send the same value again when you retry that operation:

```bash
curl -X POST https://whisper.money/api/v1/transactions \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 9b2f4c1e-5d3a-4e7b-8c6d-0a1b2c3d4e5f" \
  -d '{"description": "Coffee"}'
```

The header is optional, works on every `POST`, and is ignored on other methods.
`PATCH` and `PUT` are safe to repeat already, and repeating a `DELETE`
only answers `404` the second time.

## What happens on a retry

- **The first request succeeded.** The API sends back the same status and body
  without running the request again, with the header `Idempotent-Replayed:
true`. Keys are remembered for 24 hours.
- **The first request is still running.** The API answers `409` with the code
  `idempotency_conflict`. Wait a moment and retry.
- **The first request failed.** Errors are not remembered, so the retry runs
  again. You can fix the body and resend it with the same key.
- **Same key, different request.** Reusing a key with another path or body
  answers `422`, with the error under `Idempotency-Key`.

Keys belong to you: another user sending the same value does not collide with
yours.

A key must be between 1 and 255 characters; anything else answers `400` with
the code `invalid_idempotency_key`.
