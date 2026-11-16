---
paths:
  - 'resources/js/widgets/**'
---

# Widgets

## User widgets run in the app's own page: four layers, none optional
An agent-written widget runs in the app's JavaScript realm, so each layer must hold alone. Validator (validator/): no write rooted at a global or an import, Math/JSON/Intl only as the object of a read, call/apply/bind and DOM members are forbidden names; property access stays a denylist because widget data carries arbitrary API keys. Runtime (runtime/harden-intrinsics.ts, called from registerWidgetModules): freezes the static side of the built-ins, their global bindings and the functions on reachable prototypes, never the prototype objects (override mistake). Registry exports, widget data and element props are frozen. Any change to validator/ or harden-intrinsics.ts needs WidgetRules::VERSION bumped and the hash in WidgetRulesVersionTest updated, so stored widgets are re-checked (WidgetDefinitionService::recheck, widgets:revalidate). Compiled code never leaves the server without that recheck.

## A widget's TransactionList is the app's transactions table, on the host's rows
<TransactionList> is TransactionTable over an in-memory source (custom-dashboards/widget-transaction-table.tsx), handed in through WidgetTransactionTableProvider; the SDK never imports the table, the preview build has no Inertia. The host builds every row from the data IT fetched, looked up by id: the widget only chooses ids, order, limit and the displayRow/badgeOf formatters, and only the user's own categories and labels reach the editable table. Without a host (preview, edit mode, readOnly) the SDK draws read-only rows from transaction-base-columns.tsx and split-breakdown.tsx, which must not import router or editable cells. Widget rows are the /api/v1 shape: a split is one row with its lines. The rules of transactions-table.md apply to these files too.
