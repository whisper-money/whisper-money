---
paths:
  - 'app/Http/Controllers/Api/V1/**'
  - 'app/Http/Resources/V1/**'
---

# Public API v1

## The spec documents itself from the code: write for it
Scramble (`dedoc/scramble`) builds the OpenAPI spec of every route in the `api-v1` middleware group, and `php artisan api:docs` turns that spec into the reference pages under `resources/docs/documentation/70-api/80-reference*`. A test (`tests/Feature/ApiV1/ApiReferenceTest.php`) fails when the committed pages differ from what the code generates, so **run `php artisan api:docs` and commit its output after touching any v1 controller or resource**. Never edit those pages by hand.

Per endpoint, only describe what the controller itself does:

- **Controller:** `#[Group('Accounts', '<one or two sentences about the resource>', weight: N)]` on the class (the tag becomes one reference page; `weight` orders them, Spaces is 10). The method docblock's first line is the summary (`List accounts`, `Create an account`) and the paragraph under it the description. Write both for an API user, not for us: no class names, no implementation notes.
- **Resource:** `#[SchemaName('Account')]` on the class, and a docblock on every key of `toArray()` with a one-line description, `@example` and `@format` (`uuid`, `date`, `date-time`) where it applies. The examples become the example responses, so make them realistic and fixed (no `now()`).
- **Validation:** use a FormRequest; Scramble documents the body fields and the 422 from its rules. Add a docblock with a description (and `@example`) above each rule.

Never document these by hand: `OperationConventions` (`app/Http/ApiV1/Documentation`) adds them to every operation:

- the token ability (`x-token-ability`: `api:read` for GET, `api:write` otherwise), the 401, 403 and 429 errors and the rate-limit headers;
- the 404 on any route with a path parameter;
- the optional `Idempotency-Key` header, its 400/409 and the 422 on every POST;
- `#[ScopedToSpace]` on a method that calls `$this->space($request)`: the `space` parameter and its 422;
- `#[Paginated(AccountResource::class)]` on a method that answers through `CursorPaginator::paginate()`: `limit` / `starting_after` / `ending_before`, the `{data, has_more}` envelope and the 400.

Errors all come from `ErrorRenderer`, so a new error `code` thrown through `ApiV1Exception` belongs in `SharedComponents` (if it is a new status) and in the Errors guide (`70-api/50-errors-{en,es}.md`).

## The spec route is outside the group on purpose
`GET /api/v1/openapi.json` is registered in `bootstrap/app.php`, not in `routes/api-v1.php`, so it is public and unthrottled. It serves Scramble's cache, which `php artisan scramble:cache` fills on deploy (`docker/entrypoint.sh`). Scramble's own UI and JSON routes stay off (`Scramble::ignoreDefaultRoutes()` in `ApiDocumentationServiceProvider`).
