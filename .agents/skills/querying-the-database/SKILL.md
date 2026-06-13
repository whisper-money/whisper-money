---
name: querying-the-database
description: "Query the local or production database from the CLI via the `agent:db` artisan command. Activates when the user asks to inspect, count, look up, or run a query against the database; asks 'how many X', 'what's in prod', 'check the prod DB', 'query the database'; or mentions production data, the prod database, or running SQL."
metadata:
  author: whisper-money
---

# Querying the Database

## When to Apply

Activate this skill whenever you need to read data from the database to answer a
question — especially anything about **production** data. Prefer this command over
the `tinker`, `database-query`, or `database-schema` Boost tools when the user asks
about prod, since those default to the local connection.

## The `agent:db` command

Runs a query (`SELECT`, `SHOW`, `DESCRIBE`, `DELETE`, etc.) and prints the result.

```bash
php artisan agent:db "<query>"
```

### Options

- `--format=json` (default) — pretty-printed JSON, best for parsing the result yourself.
- `--format=table` — classic console table, best when showing the result to the user.
- `--prod` — run against the **production** database (the `prod` connection backed by
  `PROD_DB_URL`). Without this flag the query runs against the local DB.

### Examples

```bash
# Local, JSON (default)
php artisan agent:db "select id, email from users limit 5"

# Local, human-readable table
php artisan agent:db --format=table "select count(*) as total from transactions"

# Production
php artisan agent:db --prod "select count(*) from users"
php artisan agent:db --prod --format=table "select status, count(*) from subscriptions group by status"
```

## Guidelines

- **Be careful with `--prod`**: this is live customer data. Only run prod queries the
  user explicitly asked for, keep them scoped (add `LIMIT`, filter by id), and never
  dump large or sensitive datasets unprompted. This app is privacy-first.
- Use `--format=json` when you need to read the values to continue working; use
  `--format=table` when presenting results back to the user.
- Inspect schema first with `database-schema` (local) when you're unsure of column
  names before writing a query.
