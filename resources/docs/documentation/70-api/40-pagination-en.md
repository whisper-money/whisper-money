# Pagination

Lists that can grow long come back a page at a time, and you walk them with a cursor: the id of the last object you received.

{{TOC}}

## Parameters

| Parameter        | Description                                                    |
| ---------------- | -------------------------------------------------------------- |
| `limit`          | How many objects to return, between 1 and 100. Defaults to 25. |
| `starting_after` | An object id. Returns the page after it.                       |
| `ending_before`  | An object id. Returns the page before it.                      |

Send either `starting_after` or `ending_before`, never both.

## The response

A page is the objects under `data`, plus `has_more`:

```json
{
    "data": [
        { "id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60" },
        { "id": "0199a3f2-71aa-7c03-8e51-6b0d9f4e2c18" }
    ],
    "has_more": true
}
```

`has_more` tells you whether there are more objects past this page, in the
direction you are walking.

## Walking a list

Ask for the first page, then pass the id of the last object you received as
`starting_after` until `has_more` is `false`:

```bash
curl "https://whisper.money/api/v1/transactions?limit=100" \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"

curl "https://whisper.money/api/v1/transactions?limit=100&starting_after=0199a3f2-71aa-7c03-8e51-6b0d9f4e2c18" \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

To walk back, pass the first id of the page you have as `ending_before`.

## Order

Each list has one fixed order, and there is no `sort` parameter:

- Transactions: newest `transaction_date` first.
- Balances: newest date first.
- Everything else: newest first, by when it was created.

A cursor is stable: objects added while you walk the list do not make you skip
or repeat the ones you have not reached yet.

A cursor id that does not exist, or belongs to another space, answers `400` with
the code `invalid_cursor`.
