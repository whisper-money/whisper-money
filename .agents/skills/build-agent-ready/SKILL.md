---
name: build-agent-ready
description: "Pick up and build the agent-ready tickets of the whisper-money board (org project #2). Use when asked to work the board, develop/implement the agent-ready Todo tickets, drain the agent-ready queue, or when run on a loop. Each pass reconciles merged work to Done, then takes ONE Todo+agent-ready issue end-to-end inside its own git worktree: moves it to In Progress, implements the solution with Pest tests, smoke-checks it in a real browser and attaches screenshots, runs two independent review agents (architecture + product) and applies their fixes, scores how confident it is the PR is bug-free and resolves the ticket, opens a PR (auto-merge only when risk:low and confidence is high — otherwise draft / human review), moves it to In review, and tears the worktree down. Loop it to drain the queue. Complements triage-todo, which is the classifier that produces the agent-ready label."
---

# Build agent-ready tickets

The worker side of the triage pipeline. `triage-todo` labels Todo issues; this skill builds the `agent-ready` ones.
One ticket per invocation (development is expensive — keep each pass bounded). The recurrence is external: `/loop <interval> /build-agent-ready`.

## No human in the loop

The `agent-ready` label IS the human's authorization to take the ticket end-to-end. Run fully autonomously — **never** call `AskUserQuestion`, pause for confirmation, or ask which merge strategy to use. The `risk:*` label plus a confidence score the skill computes from two automated reviews decide that (Steps 6–7). This holds even when you discover surprises (e.g. a recently merged PR that touched the same lines or attempted the same fix): don't stop to ask — make the call, note it in the PR body, and proceed. The only non-autonomous exits are the hard block called out below (missing `gh` scope) and "can't finish confidently" in Step 4b, which means *stop, tear the worktree down, and flag in the summary* — not ask interactively.

## Prerequisites

`gh` needs Projects v2 scope. If a command fails with `missing required scopes [read:project]`, stop and tell the user to run it themselves (interactive):

```
gh auth refresh -s read:project,project
```

You build inside a **dedicated git worktree** (Step 4), never the user's checkout — so a dirty main working tree no longer blocks you and the user can keep working while you build. Tests are already isolated: `tests/bootstrap.php` starts an ephemeral Testcontainers MySQL per run (random port, fresh DB), so they never touch the dev database — that's why parallel worktrees are safe. You need: `git`, **Docker running** (for the test container), and the Playwright MCP browser tools for the smoke check (Step 5).

## Board constants (org `whisper-money`, project #2)

```bash
PROJECT_ID="PVT_kwDODndgSc4BbXrj"
STATUS_FIELD="PVTSSF_lADODndgSc4BbXrjzhWIpII"
OPT_TODO="f75ad846"; OPT_INPROGRESS="47fc9ee4"; OPT_INREVIEW="cb8cf01d"; OPT_DONE="98236657"

move() { gh project item-edit --id "$1" --project-id "$PROJECT_ID" --field-id "$STATUS_FIELD" --single-select-option-id "$2"; }
```

`item-id` is the board item id (`PVTI_…`), from `item-list` below — not the issue number.

## Step 1 — Fetch the board

```bash
gh project item-list 2 --owner whisper-money --format json --limit 200
```

Each `.items[]` carries `id` (board item id), `status`, `labels` (flat string array), and `content` (`{type, number, url, title, body}`). Derive repo from `content.url` (`…/whisper-money/whisper-money/issues/583` → repo `whisper-money/whisper-money`, number `583`).

## Step 2 — Reconcile In review → Done (every pass, cheap)

For each item with `status == "In review"` and `content.type == "Issue"`, check whether its issue closed (our PRs carry `Closes #N`, so a merged PR closes the issue):

```bash
gh issue view <number> --repo <repo> --json state -q .state   # CLOSED ⇒ done
```

If `CLOSED`, move it: `move <item-id> "$OPT_DONE"`. Draft PRs (risk:medium/high) are merged by a human later; a future pass picks them up here.

## Step 3 — Pick ONE ticket to build

From the items, keep those with `status == "Todo"`, `content.type == "Issue"`, and `agent-ready` in `labels`. Draft issues can't be built — skip and note them.

- None left → report (including any reconciliations from Step 2) and stop.
- Otherwise take the lowest issue number. Read its `risk:*` label from `labels` (`risk:low` / `risk:medium` / `risk:high`).

Move it to In Progress immediately so the board reflects reality: `move <item-id> "$OPT_INPROGRESS"`.

## Step 4 — Spin up an isolated worktree

Work in a throwaway worktree off up-to-date `origin/main`, on its own branch, so you build with full freedom and the user's checkout is never touched.

```bash
git fetch origin
ROOT="$(git rev-parse --show-toplevel)"
WT="$(dirname "$ROOT")/worktrees/whisper-money/agent-<number>-<slug>"
git worktree add -b "agent/<number>-<slug>" "$WT" origin/main

# Deps: symlink from main — vendor+node_modules are ~1GB, copying them per worktree is wasteful.
ln -s "$ROOT/vendor" "$WT/vendor"
ln -s "$ROOT/node_modules" "$WT/node_modules"

# Env: copy the dev .env and give the server its own port (Step 5). Tests don't use this DB —
# they get an ephemeral Testcontainers MySQL, so sharing the dev DB here only affects the smoke check.
cp "$ROOT/.env" "$WT/.env"
PORT=$(awk 'BEGIN{srand(); print 8100 + int(rand()*800)}')
cd "$WT"
sed -i '' -E "s#^APP_URL=.*#APP_URL=http://127.0.0.1:$PORT#" .env
```

If the ticket ends up editing `composer.lock` or `bun.lock(b)`, replace that symlink with a real install in the worktree (`rm vendor && composer install`) — otherwise it keeps pointing at main's stale deps.

## Step 4b — Build the solution

From the worktree, implement following `CLAUDE.md` (Form Requests, Eloquent, dark-mode + Tailwind v4, Wayfinder, etc.) and activate the relevant domain skills.

- Write Pest tests for every change (CLAUDE.md enforces this) and keep new `__()` keys in `lang/es.json`.
- Commit incrementally — one coherent chunk per commit, staging explicit paths (never `git add -A`). Titles/bodies in English.
- Before opening the PR, the local CI gates must pass (Docker must be up for the test container):

```bash
vendor/bin/pint --dirty --format agent
./vendor/bin/pest --filter=<relevant>   # or the affected file(s)
bun run lint && bun run format
```

If you cannot finish the ticket confidently (hidden requirement, needs a human decision, gates won't pass), **do not open a half-baked PR**. Leave the item in In Progress, push nothing broken, tear the worktree down (Step 8), and flag it in the summary for a human. Don't invent scope.

## Step 5 — Smoke-check in the browser and screenshot it

Prove the change actually renders before opening the PR. The full `composer run dev` is the human's local loop — it owns global singletons (the `portless` name `dev.whisper.money` and the `stripe listen` forwarder), so a second copy collides with the user's. In the worktree run a self-contained server instead:

```bash
bun run build                                                          # Vite manifest
php artisan serve --port=$PORT > /tmp/agent-<number>-serve.log 2>&1 &   # background; keep the PID
```

Then drive it with the Playwright MCP tools:

1. `browser_navigate` to `http://127.0.0.1:$PORT`; if the affected page needs auth, log in with the existing dev/demo account.
2. Go to the page(s) the ticket touches and exercise the change.
3. `browser_console_messages` — any error means it isn't done; fix and re-check.
4. `browser_take_screenshot` of the relevant view(s) into the worktree, e.g. `tests/Browser/.agent/<number>-<view>.png`.

Then `kill <PID>`. If you can't get the page to render cleanly, treat it as "can't finish confidently" (Step 4b).

Attach the shots to the PR via a **never-merged `agent-screenshots` branch in the same private repo** — keeps them out of `main` and off third-party image hosts (this is a privacy-first finance app; the dev DB the server rendered may hold real data):

```bash
ASSETS="$(dirname "$ROOT")/worktrees/whisper-money/.screenshots"
git worktree add "$ASSETS" agent-screenshots 2>/dev/null \
  || git worktree add -b agent-screenshots "$ASSETS" origin/main
mkdir -p "$ASSETS/<number>" && cp tests/Browser/.agent/*.png "$ASSETS/<number>/"
git -C "$ASSETS" add "<number>" \
  && git -C "$ASSETS" commit -m "screenshots: #<number>" \
  && git -C "$ASSETS" push -u origin agent-screenshots
git worktree remove "$ASSETS" --force
```

Reference each in the PR body so the reviewer sees the result inline:
`![<view>](https://github.com/<owner>/<repo>/blob/agent-screenshots/<number>/<view>.png?raw=true)`

<!-- ponytail: dedicated private branch is the only fully-CLI, privacy-safe way to get images into a PR — GitHub's native upload (user-images CDN) needs a browser session. Ceiling: if the blob `?raw=true` URL doesn't render inline for the private repo, the reviewer still has a one-click link; switch to GitHub's attachment upload only if inline rendering becomes a hard requirement. -->

## Step 6 — Dual review, apply fixes, score confidence

Before any PR, get two **independent** reviews of the committed diff (`git diff origin/main...HEAD` in the worktree). Spawn **both in parallel** with the Agent tool (`general-purpose`), each given the ticket body, the diff, and the files it touches:

- **Architecture reviewer** — code quality, cleanliness, testability, scalability, adherence to `CLAUDE.md` (Form Requests, Eloquent / no N+1, Wayfinder, dark mode), whether the change is actually covered by the new Pest tests, dead code, leaky abstractions.
- **Product reviewer** — does it truly resolve the ticket? Bugs and edge cases, regressions, UX/copy, accessibility, dark-mode rendering. Hand it the Step 5 screenshots.

Ask each to return findings as JSON — `[{severity: "critical"|"major"|"minor", area, file, description, suggested_fix}]` — plus a one-line overall verdict.

**Act on the output autonomously:**

- Fix every **critical** and **major** finding in the worktree, commit it, and re-run the gates (Step 4b). If a fix changed UI or behavior, re-run the smoke check (Step 5).
- **minor**/cosmetic: fix if cheap, otherwise list under "Reviewer notes (deferred)" in the PR body — don't gold-plate.
- A finding that's wrong or out of scope: note why in the PR body and move on. Never ask.

**Confidence score (0–100)** — how safe the PR is, across the three things "safe" means: (1) **bug-free** (logic correct, tests prove it), (2) **no new errors** (gates green, smoke check + console clean, no regression), (3) **resolves the ticket** (the issue's acceptance criteria are demonstrably met). Start at 100 and apply caps:

- Any unresolved critical/major finding, failing gate, or browser console error → **≤ 50**.
- Acceptance criteria not clearly met, or a critical path left untested → **≤ 65**.
- All green, criteria met, only minor/cosmetic notes left → **85–100**.

Record the score and a one-line reason — both go in the PR body.

<!-- ponytail: the score is a self-assessment, and 85 is a deliberately conservative auto-merge bar — it only ever gates risk:low tickets, so the downside of being wrong is a small reverted PR, not a money/auth incident. Tune the threshold (or add a third reviewer / a refute pass) only if low-risk auto-merges start landing bugs; until then one number + caps is enough. -->

## Step 7 — Open the PR and decide auto-merge

Push the branch and open a PR whose body starts with `Closes #<number>` (so merging closes the issue, which Step 2 turns into Done) and includes: what + why, the **confidence score + reason**, any deferred reviewer notes, and the Step 5 screenshots.

```bash
git push -u origin HEAD
```

Auto-merge is gated on **both** the risk label and the confidence score — still fully autonomous, never ask:

- **risk:low AND confidence ≥ 85** → real PR, then enable auto-merge (squash):

  ```bash
  gh pr create --repo <repo> --base main --title "<English title>" --body "<body>"
  gh pr merge --auto --squash
  ```

  If `gh pr merge --auto` fails (auto-merge disabled on the repo), leave the PR open (non-draft) and note it — a human merges it.

- **everything else** → no auto-merge, hand it to a human:
  - `risk:low` but **confidence < 85** → open a real (non-draft) PR; the confidence section spells out what's unresolved.
  - `risk:medium` / `risk:high` → always `--draft`, regardless of score.

  ```bash
  gh pr create --draft --repo <repo> --base main --title "<English title>" --body "<body, incl. what a reviewer should check>"
  ```

Then move the board item to In review: `move <item-id> "$OPT_INREVIEW"`.

## Step 8 — Tear down the worktree

The branch is pushed, so the worktree is disposable. Remove it (keep the branch — the PR needs it):

```bash
cd "$ROOT"
git worktree remove "$WT" --force
```

Always tear down — even when you bailed in Step 4b (the board item just stays In Progress and your summary flags it). The `vendor`/`node_modules` symlinks vanish with the worktree; nothing in main is touched.

## Step 9 — Summary

Short report: which ticket was built (number, risk, **confidence score + reason**, auto-merge on/off or draft), the dual-review outcome (findings fixed vs deferred), the smoke-check result + screenshot links, anything reconciled to Done, any ticket left stuck in In Progress and why, and any draft issues skipped. End by noting the queue isn't empty if more `agent-ready` Todos remain — loop again to continue.

<!-- ponytail: IDs hardcoded from the live board (gh project field-list 2 --owner whisper-money) — they're stable node ids, not secrets. If a move fails with an unknown-option error the board was recreated: re-run field-list and update the constants. One ticket/pass + lowest-number pick keeps each run bounded and the board honest; looping drains the queue. -->
