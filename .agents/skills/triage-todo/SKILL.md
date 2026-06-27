---
name: triage-todo
description: "Classify the GitHub Projects v2 'Todo' tickets of the whisper-money org (project #2). Use when asked to triage, classify, or label the Todo column of the board, or when run on a loop. For each Todo issue it inspects the affected code and git history in a clean checkout (a read-only worktree pinned to origin/main), estimates an automation risk from concrete signals (blast radius, reversibility, sensitive paths, fragility), decides a disposition (agent-ready / need-human / need-details) and a risk level (risk:low / risk:medium / risk:high), applies the matching labels, and — only for need-details — posts a comment listing what is missing. One pass per invocation; loop it with /loop."
---

# Triage Todo tickets

One classification pass over the **Todo** column of `https://github.com/orgs/whisper-money/projects/2`.
The recurrence is external: run `/loop <interval> /triage-todo` to repeat.

## Prerequisites

`gh` must have Projects v2 scope. If a command fails with `missing required scopes [read:project]`, stop and tell the user to run (it is interactive, so they run it themselves):

```
gh auth refresh -s read:project,project
```

Org: `whisper-money` · Project number: `2` · Status option to process: `Todo`.

## Step 1 — Fetch the Todo items

```bash
gh project item-list 2 --owner whisper-money --format json --limit 200
```

Parse `.items[]`, keep only those with `status == "Todo"` **and** `content.type == "Issue"`.
Draft issues (`content.type == "DraftIssue"`) cannot be labeled or commented — skip them and note their titles in your summary so the user can convert them to real issues.

For each kept item you have `content.title`, `content.body`, `content.url`. Derive the repo + number from the URL (e.g. `…/whisper-money/whisper-money/issues/123` → repo `whisper-money/whisper-money`, number `123`).

## Step 2 — Skip already-classified

Read the issue's current labels:

```bash
gh issue view <number> --repo <repo> --json labels -q '.labels[].name'
```

If it already has a disposition label (`agent-ready`, `need-human`, or `need-details`), **skip it** — it was classified in a previous pass. (To force a re-classification, the user removes the labels; mention this in your summary.)

## Step 3 — Classify

Read title + body. If the body embeds images (`![](url)`) and you need them to judge, download with the gh token and view them, then delete:

```bash
curl -sL -H "Authorization: token $(gh auth token)" "<image-url>" -o /tmp/triage-img && echo /tmp/triage-img
```

### 3a — Ground it in a clean checkout of the code

Don't classify from the issue text alone — confirm it against the code, and do it against a **clean checkout** so the user's in-progress edits or whatever branch they're on never skew your read. Keep one persistent analysis worktree pinned to `origin/main`, refresh it at the start of each pass, and run all Grep/Glob/`git log` there:

```bash
git fetch origin --quiet
ANALYSIS="$(dirname "$(git rev-parse --show-toplevel)")/worktrees/whisper-money/triage-analysis"
git worktree add "$ANALYSIS" origin/main 2>/dev/null || git -C "$ANALYSIS" reset --hard origin/main --quiet
cd "$ANALYSIS"
```

No deps or server needed — triage only reads source and history. (`gh` commands keep working from here; they all pass `--repo` explicitly.)

Both labels hinge on facts the issue rarely states: *which files change* (drives risk) and *whether the scope is truly bounded* (drives disposition).

- **Find the affected code** for the symbols, routes, UI strings, or components the issue names (Grep/Glob). If nothing concrete turns up to change, that's evidence for `need-details`.
- **See what those files touch.** A path leading into billing (`Cashier`), auth (`Fortify`), `database/migrations`, `Pennant` features, or the bank/broker integrations (Enable Banking, Interactive Brokers) is a money/auth/data path → push risk up, and reconsider `need-human`.
- **Read the git history of that area** for context the issue omits — prior attempts, recent reverts, repeated bug-fixes (a fragility signal):

```bash
git log --oneline -15 -- <path>       # recent history of the affected file/dir
git log --oneline -S "<symbol>" -10   # commits that touched a specific string/symbol
```

### 3b — Pick the labels

Pick exactly **one disposition** and **one risk** level.

### Disposition

- **need-details** — Too underspecified to act on: vague goal, no acceptance criteria, ambiguous scope, references context you can't see, or image-only with no explanation. This wins over the other two: if you can't tell what "done" means, it's `need-details`.
- **need-human** — Well-specified, but resolving it needs human judgment a coding agent shouldn't make alone: product/UX/copy decisions, pricing or plan changes, security trade-offs, data deletion/migration calls, external coordination, or anything touching real user financial data.
- **agent-ready** — Well-specified, bounded, and a coding agent can take it end-to-end (implementation + Pest tests) without a human decision in the loop. Typical: localized UI tweaks, adding tests, refactors with clear intent, copy fixes with the copy provided, well-scoped bug fixes with repro.

### Risk (of letting an agent resolve it automatically)

This is a privacy-first **personal finance** app — financial/PII paths weigh heavily. Don't eyeball it; estimate from the concrete signals you gathered in 3a. **Start at `risk:low` and escalate** as signals fire:

- **Blast radius** — how far the change ripples. Count the call sites of the symbol you'll touch:

  ```bash
  grep -rln "<symbol>" app resources/js | wc -l
  ```

  1–2 files of leaf code → stays low. A shared util, a base component, or many references → at least medium.
- **Reversibility** — a DB migration, a destructive/irreversible data change, or a Pennant rollout is hard to undo → high. Copy / small UI / test-only is trivially revertible → stays low.
- **Sensitive path** — does the affected code live in or import billing (`Cashier`), auth (`Fortify`), `database/migrations`, `Pennant`, or the bank/broker integrations (Enable Banking, Interactive Brokers)? Any hit → high.
- **Fragility** — from the `git log` in 3a: a recent revert or a cluster of fixes in the same spot bumps the level up one (and can turn an apparently simple ticket into `need-human`).
- **Test cover** — is there a Pest test already exercising the area? If yes, an agent can change it more safely (don't bump up). Untested money/data logic → bump up.

Then settle on one level:

- **risk:low** — Isolated, easily reversible, small blast radius, no money/auth/data path. Copy, small UI, docs, added tests.
- **risk:medium** — Shared code or business logic, several files, needs careful testing, reversible with effort.
- **risk:high** — Any sensitive path, any migration / irreversible change, or a broad blast radius. When torn between medium and high on a money/auth/data path, choose high.

## Step 4 — Apply labels

Ensure the labels exist in that repo (safe to re-run), then add the two you chose:

```bash
ensure() { gh label create "$1" --repo <repo> --color "$2" --description "$3" 2>/dev/null || true; }
ensure "risk:low"     "0E8A16" "triage: low automation risk"
ensure "risk:medium"  "FBCA04" "triage: medium automation risk"
ensure "risk:high"    "B60205" "triage: high automation risk"
ensure "agent-ready"  "1D76DB" "triage: ready for an automated agent"
ensure "need-human"   "D93F0B" "triage: needs human intervention"
ensure "need-details" "BFBFBF" "triage: missing details before starting"

gh issue edit <number> --repo <repo> --add-label "<disposition>" --add-label "<risk>"
```

## Step 5 — Comment only when need-details

If and only if the disposition is `need-details`, post one comment listing the concrete missing details as questions, so the user can reply to fill them in:

```bash
gh issue comment <number> --repo <repo> --body "<!-- triage-todo -->
**Triage: needs details before this can start.**

To classify and (maybe) automate this, I need:
- <specific question 1>
- <specific question 2>

Reply here with the details and I'll re-triage on the next pass (remove the \`need-details\` label to force it)."
```

Do not comment for `agent-ready` or `need-human` — the labels say it.

## Step 6 — Summary

End with a short table: ticket → disposition + risk, how many were skipped (already classified), and any draft issues that couldn't be labeled.

<!-- ponytail: idempotency is the single rule "skip if it already has a disposition label" — covers both double-labeling and double-commenting. If the user later wants auto-re-triage on edit, compare issue updatedAt against the label event; add then, not now. The analysis worktree is persistent and reused across loop passes (reset to origin/main each pass), not created per ticket — triage only reads, so no deps/DB/teardown. -->
