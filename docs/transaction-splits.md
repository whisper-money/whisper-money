# Transaction splits

New splits follow upstream's model: `TransactionSplitter` replaces a transaction with 2–20 child transactions (parts) that carry `split_parent_id`, and soft-deletes the original, which keeps its bank ids and fingerprint so a sync or a re-import does not bring it back. Merging any part soft-deletes every part and restores the original with the category it had. A part cannot be split again or deleted on its own, and its date, account, amount, currency, description and counterparties are locked; its category, labels and notes stay editable. Splitting is gated by the `TransactionSplitting` flag; merging never is.

The rest of this document describes the fork's earlier model, split lines in `transaction_splits`. Rows that still carry lines are read as before, but no path writes lines any more: such a row cannot be split, merged or recategorized until it is converted to parts.

## Data model

`transactions` remains the account ledger and source of truth for amount, date, currency, account, description, notes, labels, and counterparties. `transaction_splits` stores only category postings: category, signed amount, and position.

A split transaction:

- has at least two non-zero lines;
- has lines with the same sign as the parent;
- has line amounts summing exactly to the parent amount in minor units;
- uses categories belonging to the same user and space;
- uses one category type across all lines (`expense` or `income`);
- has a null parent `category_id` and cleared AI/rule provenance.

Positive refunds may use expense categories. They reduce expense analytics and budget consumption.

## API contract

`POST /api/v1/transactions/{id}/split` takes the parts and answers `201` with them, each a transaction with its `split_parent_id`:

```json
{
  "splits": [
    { "amount": -6000, "category_id": "food-uuid", "label_ids": ["label-uuid"] },
    { "amount": -4000 }
  ]
}
```

`category_id` and `label_ids` are optional and must be the caller's own in the space. The amounts must add up to the original and share its sign (`422` under `splits`); a part, or a row still split into lines, is a `422` under `transaction`; `403 splitting_disabled` while the flag is off. `POST /api/v1/transactions/{part-id}/merge-splits` takes no body and answers the original. A row still split into lines keeps its ordered `splits`, `is_split` and `split_count` in responses and incremental sync.

## Financial behavior

- Account balances and bank synchronization always use the parent amount once.
- Category analytics use effective postings: the parent category/amount for a normal transaction, or split lines for a split transaction, never both.
- Transaction counts remain parent counts.
- Largest-expense results use the parent amount and display `Split` as the category.
- Category filters match parent and split categories while returning each parent once.
- Category-filtered analysis includes only matching postings.
- Category budgets consume matching split portions. Label matches consume the full parent and take precedence over category matches.
- Catch-all budgets consume unclaimed expense postings only.

## Write protections

Bulk category changes, automation rules, and AI categorization skip split transactions. MCP category and amount writes reject split transactions; MCP reads expose their lines. Notes and labels remain parent fields and may still be changed.

## MCP writes

`split_transaction` takes `transaction_id` and `splits` (parts of `amount`, optional `category_id` and `label_ids`, any of the space's categories and labels) and returns `split_into` and the `parts`. `merge_transaction_splits` takes the id of any part and returns the original. Both tools are marked destructive. `update_transaction` refuses a part's core fields, `delete_transaction` refuses a part, and `categorize_transaction` and `update_transaction` still refuse a category or amount change on a row still split into lines. Splitting follows the `TransactionSplitting` flag; merging stays available while it is off. OAuth connections can use write tools under the current MCP policy; Sanctum tokens need the `mcp:write` ability.

Bank importers do not create or modify splits. Deleting a parent cascades to its lines. Account soft-delete and restore preserve the parent and its lines; force-deleting the parent removes the lines through the foreign-key cascade. Categories referenced by split lines cannot be deleted, and their type cannot be changed while they participate in a split.

Split replacement follows one mutation boundary and a stable lock order: parent transaction, referenced categories ordered by ID, then existing split rows. Deadlock retries wrap the complete outer mutation rather than individual statements. A replacement or unsplit operation touches the parent once after the final line state exists so model observers, budget reassignment, and delta-sync clients see one coherent update. Split lines do not carry labels, provenance, or mixed category types.

## Feature flag and offline sync

`App\Features\TransactionSplitting` is enabled by default in this fork. Laravel Pennant can disable nonempty split creation and replacement per user. A missing `splits` field remains a metadata-only update, and `splits: []` remains available to remove existing lines while the flag is off. Reads continue to include existing splits. The Inertia feature prop hides the start-split control and makes existing lines read-only while retaining the remove action.

The browser keeps persisted `TransactionSplit` records separate from split mutation DTOs. Incremental sync and IndexedDB store ordered `splits`, `is_split`, and `split_count`. Notes-only edits omit `splits`, `category_id`, and `amount`, avoiding accidental replacement from stale local state.

## Performance baseline and SQL decision

`tests/Performance/TransactionSplitVolumeTest.php` builds a deterministic MySQL dataset with 10,000 unsplit transactions, 2,000 split parents, 2–5 lines per parent, two accounts in one currency, category hierarchies, refunds, and isolation sentinels outside the user/date scope. It verifies 12,000 parent transactions, 17,000 effective postings, exact totals per category, exact hierarchy rollups per root, and bounded query counts.

Local Docker baseline on PHP 8.4.21, after fixture creation. Each invocation measures three executions per path and reports the median and worst time:

| Path | Invocation 1 median / worst | Invocation 2 median / worst | Queries | Peak additional memory |
|---|---:|---:|---:|---:|
| Category spending and hierarchy rollup | 1,938.66 / 1,948.99 ms | 1,853.80 / 2,034.17 ms | 5 | 78 MB |
| Dashboard effective-posting expansion | 1,834.97 / 2,351.34 ms | 1,874.90 / 2,024.20 ms | 4 | 6 MB |

These are local diagnostic baselines, not brittle CI limits. Query counts and exact accounting are asserted; runtime and memory are reported. At this representative volume, the current eager-loaded PHP expansion remains predictable and accounting-correct, so this hardening does **not** add an SQL effective-postings projection. Reconsider only with production-like profiling that shows these paths materially exceed the deployment's latency or memory budget.

## Deployment and rollback

Before deploying hardened split writes, run the read-only integrity gate against a private production database copy. That rehearsal is an external deployment gate and is not represented by the synthetic local test database:

```bash
php artisan transactions:audit-splits --json --fail-on-invalid
```

The JSON artifact contains only IDs, anomaly codes, and counts. Resolve every anomaly before deployment.

The same command also runs nightly with `--fail-on-invalid`, where stdout goes nowhere. Whenever it finds anomalies it logs `Transaction split audit found anomalies` at error level with the counts per code and up to ten IDs per code, so the scheduler failure in the error tracker arrives with the breakdown attached instead of a bare exit code. Re-run the command with `--json` to get the complete list. Rebuild historical budget assignments and advance parent delta-sync cursors only after a clean audit:

```bash
# Required preview; performs no writes.
php artisan transactions:reconcile-splits

# Explicit, resumable execution in short chunks.
php artisan transactions:reconcile-splits --execute --chunk=200
```

Reconciliation never changes split line IDs, categories, amounts, positions, or timestamps. Re-running it is safe: budget rows use their existing unique identity and parent cursors advance once per execution.

The migration is additive and sparse; no backfill is required. Deploy backend and frontend together. After deployment, monitor for:

- split sums differing from parent amounts;
- parents having both `category_id` and split rows;
- budget totals failing to reconcile.

Code rollback is transparent only before split data exists. Flattening existing splits loses classification detail and must be an explicit operation.
