---
paths:
  - 'app/Http/Controllers/Api/V1/**'
  - 'app/Http/Requests/Api/V1/**'
  - 'app/Http/Resources/V1/**'
  - 'app/Services/SavingsGoals/SavingsGoalIncomeWithholding.php'
  - 'app/Mcp/**'
  - app/Services/TransactionSplitter.php
  - routes/ai.php
---

# Public API and MCP

## Scope: the API answers own rows, MCP stays space-wide
`/api/v1` answers the caller's own rows, in a shared space too, and ids in a body are checked as the caller's. MCP tools stay space-wide on purpose: never port upstream's own-rows narrowing onto them, and never widen the API to match them. A figure shared by both surfaces belongs in a presenter or service; a difference between them belongs in the API's resource or form request, so MCP and the web keep their output.

## Income-rate savings goals are withheld in a shared space
An income-rate goal is measured against its whole space's income, so its target reveals a co-member's income even with `income_base` blanked. `SavingsGoalIncomeWithholding` nulls every figure derived from it on the API and refuses creating one there. It checks current membership only: whoever adds leaving a space or moving a goal must also treat periods frozen while the space was shared, or the figures come back.

## Encrypted fields read as an empty string
An account name or a note still encrypted on the device reads `''` on the API, never ciphertext. A client writing back the `''` it read must not erase the value, so a blank `notes` on an encrypted note is a 422. Never derive a stored value from an encrypted field: an auto-tag rule built from an encrypted account name stores ciphertext and can never match.

## Split and merge go through TransactionSplitter; a row with old lines is one whole row
`POST /api/v1/transactions/{id}/split` and `split_transaction` create parts (201 with the parts, each carrying `split_parent_id`); `merge-splits` and `merge_transaction_splits` take any part and answer the original. Only split is gated by the TransactionSplitting flag. A part's core fields are locked and it cannot be deleted. A label may sit on several parts, so never validate part labels with `distinct`, which compares across parts. A transaction still split the old way is read-only as a split: a list filtered by category or label returns it once, with its `amount` and every line in `splits`, those outside the filter too, and it is refused by split until converted.

## OAuth metadata: route:cache and scopes
Production runs `route:cache`, which restores the package's closures on the package class, so overriding `protectedResourceMetadata()` answers `mcp:use` there. Metadata and route changes go in `OAuthRegistrar` as explicit routes, and need a real `route:cache` probe. Authorization server metadata lists `mcp:use` alone: a client requesting everything listed there would ask for both surfaces and get `invalid_scope`. `api:use` is advertised on the `/api/v1` resource metadata only.

## Dashboard and widget MCP tools exist only with the CustomDashboards flag; the preview is an inlined MCP App
Custom dashboards are gated by the Pennant flag CustomDashboards alone (no plan): the route middleware custom-dashboards, the policies, and shouldRegister() plus GuardsCustomDashboards on every dashboard or widget tool and on the WidgetPreview resource; tests enumerate routes and tools, so a new one without the gate fails. Dashboards and widgets are per user, not per space, and widget data is the owner's own rows through the gateway. The widget preview MCP App inlines public/build/widget-preview/* (second step of bun run build; without it a 'not available' page), takes its payload from the tool result's _meta (WidgetPreviewPayload::MetaKey) and links the widget as an inline module (link-inline.ts); a tool returning _meta answers a ResponseFactory, which McpTool::respond() allows.
