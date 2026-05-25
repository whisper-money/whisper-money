---
description: Pick or fix a Sentry issue end-to-end, then PR and watch CI
argument-hint: "[issue-id-or-url]"
---
Run Sentry issue repair workflow for this project only.

Input: `$ARGUMENTS`

Goal:
1. Use provided Sentry issue id or URL. If none provided, choose the highest-impact unresolved issue using frequency, affected users, recency, and production impact.
2. Create/switch to git branch named exactly after the Sentry short issue id.
3. Investigate root cause, implement fix, and add/update tests.
4. Open PR and watch CI until green.

Rules:
- Protect local work. Start with `git status --short`. If uncommitted changes exist, stop and ask before branching.
- Never expose secrets. Do not print tokens, `.env`, Sentry auth, DB URLs, or PII.
- Use the Sentry CLI (`sentry`) first. Prefer stored OAuth login over env build tokens; if `SENTRY_AUTH_TOKEN` is invalid or too narrow, run CLI commands as `env -u SENTRY_AUTH_TOKEN sentry ...` unless `SENTRY_FORCE_ENV_TOKEN=1` is intentionally set.
- Never print raw Sentry JSON that may contain PII. Redact emails, user IDs when summarizing. Keep secrets out of output.
- For Laravel ecosystem changes, use `application-info` and `search-docs` before code changes.
- Activate/read relevant project skills when touched: Pest tests, Inertia React, Wayfinder, Tailwind, Fortify, Pennant.
- Every code change needs programmatic verification. Add or update a focused Pest/test when feasible. Run minimum affected tests. Run `vendor/bin/pint --dirty --format agent` after PHP edits.
- Prefer small surgical fix. No dependency changes without approval.

Workflow:
1. Identify issue:
   - Confirm auth and org/project access with `sentry auth status`, `sentry org list --json`, and `sentry project list <org> --json` when needed.
   - If `$ARGUMENTS` is a Sentry URL, extract `/issues/<numeric-id>/` or the visible short issue id, then fetch it with `sentry issue view <issue> --json --fields id,shortId,title,culprit,permalink,level,status,substatus,count,userCount,firstSeen,lastSeen,project,metadata,priority,platform,isUnhandled`.
   - If `$ARGUMENTS` is a bare issue id or short id, fetch it with `sentry issue view <issue> --json --fields id,shortId,title,culprit,permalink,level,status,substatus,count,userCount,firstSeen,lastSeen,project,metadata,priority,platform,isUnhandled`.
   - If no args, list unresolved production issues with both frequency and user sorting, then compare top results:
     - `sentry issue list <org>/<project> --query 'is:unresolved environment:production' --sort freq --limit 25 --json --fields id,shortId,title,culprit,count,userCount,lastSeen,permalink,priority,metadata,project`
     - `sentry issue list <org>/<project> --query 'is:unresolved environment:production' --sort user --limit 25 --json --fields id,shortId,title,culprit,count,userCount,lastSeen,permalink,priority,metadata,project`
   - Pick best impact score: high event count, high user count, recent lastSeen, production environment, clear actionable stack.
   - Record chosen short issue id and Sentry URL in notes.
2. Branch:
   - Derive branch name from Sentry short issue id only, e.g. `WHISPER-MONEY-123`.
   - Run `git switch -c <issue-id>`; if branch exists, `git switch <issue-id>`.
3. Investigate:
   - Fetch latest issue details and spans with `sentry issue view <issue> --spans all --json`.
   - Fetch recent events with `sentry issue events <issue> --limit 10 --full --json`.
   - Extract stack trace, breadcrumbs, environment, release, tags, URLs/routes, affected users count, trace/span data, and suspect queries. Redact PII in notes.
   - For event details if needed, use `sentry event view <event-id> --json` or `sentry api <endpoint> --json`.
   - Reproduce locally using tests or focused command. Inspect app logs/browser logs if relevant.
   - If root cause not obvious after issue/event data, run Seer with `sentry issue explain <issue> --json` and/or `sentry issue plan <issue> --json`.
4. Fix:
   - Read nearby code and conventions first.
   - Implement minimal fix.
   - Add regression test covering Sentry failure path.
5. Verify:
   - Run targeted test command, e.g. `php artisan test --compact --filter=<test-or-class>`.
   - Run lint/format commands required by touched files.
   - If failures occur, fix and rerun until pass.
6. PR:
   - Commit changes with concise conventional commit.
   - Push branch.
   - Create PR with `gh pr create`, including Sentry issue link, root cause, fix summary, and verification commands.
   - Get PR number from `gh pr view --json number --jq .number` if needed.
   - Watch CI every 10 seconds with `gh pr checks <number> --watch --fail-fast --interval 10`.
   - If CI fails, inspect logs, fix, commit/push, and watch again until green.

Output when done:
- Issue id + Sentry URL
- Branch name
- Root cause
- Fix summary
- Tests/commands run
- PR URL
- CI status
