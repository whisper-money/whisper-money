---
paths:
  - 'app/Console/Commands/AgentDatabaseCommand.php'
---

# agent:db

## Read-only against prod is enforced by the credentials, not by this command
`agent:db` hands the raw query argument straight to `DB::connection($connection)->select()`.
`select()` executes whatever statement it is given — it does not restrict it to reads — so
nothing in this command, and nothing in the `querying-the-database` skill, blocks a write.

What blocks it is `PROD_DB_URL`: it points at a MySQL user with read-only grants, so an
`INSERT`, `UPDATE`, `DELETE` or DDL against `--prod` fails with an access-denied error from
the server. That is the whole guardrail, and it lives outside the codebase.

Two things follow:

- **A failing write against prod is the guardrail working.** Never fix it by widening that
  user's grants or repointing `PROD_DB_URL` at an account with more rights. Run it against
  the local connection, or ship it as a migration or a release command.
- **Do not add a write path here** (a `--write` flag, a second prod connection, a
  `DB::statement()` branch). The command is safe to hand to an agent only because the
  credentials it uses cannot write.

Adding a `SELECT`-only check in PHP would be redundant with the grants and easy to bypass by
rewriting the query, which is why there isn't one.

Confirm the grants are still read-only:

```bash
php artisan agent:db --prod --format=table "show grants for current_user"
```
