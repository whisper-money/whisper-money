---
description: Cut a new release from origin/main using release-it
argument-hint: "<patch|minor|major>"
---

Cut a new release of this project using `release-it`. Bump type: **$1**

If `$1` is empty or not one of `patch`, `minor`, `major`, stop and ask the user which bump to perform.

## Context

- Package manager: `npm` (see `package.json` scripts).
- Release tool: [`release-it`](https://github.com/release-it/release-it) v19 with `@release-it/conventional-changelog` (angular preset).
- Config: `.release-it.json` — commits `chore: release v${version}`, tags `v${version}`, creates GitHub Release, updates `CHANGELOG.md`, does NOT publish to npm.
- Run command: `npm run release -- <patch|minor|major>` (script is `release-it`).

## Steps

1. **Verify clean state on `main`:**
   - `git fetch origin --tags --prune`
   - Ensure the working tree is clean: `git status --porcelain` must be empty. If not, stop and report.
   - Check out `main` and fast-forward to `origin/main`:
     - `git checkout main`
     - `git pull --ff-only origin main`
   - Confirm HEAD equals `origin/main`: `git rev-parse HEAD` == `git rev-parse origin/main`. If not, stop.

2. **Sanity checks:**
   - Show current version from `package.json` and the latest tag (`git describe --tags --abbrev=0`) so the user sees what will be bumped.
   - Preview the changelog entries that will be included: `git log $(git describe --tags --abbrev=0)..HEAD --oneline`.
   - Run a dry run first: `npm run release -- $1 --dry-run --ci`. Show the proposed new version, commit, tag, and GitHub release. If it fails, stop and report.

3. **Confirm with the user** before the real release. Show the target version and ask to proceed.

4. **Perform the release** (interactive, so the user can confirm each step release-it asks):
   - `npm run release -- $1`
   - Requires `GITHUB_TOKEN` (or `gh auth`) for the GitHub release step. If the token is missing, warn the user before running.

5. **Post-release:**
   - Confirm the new tag exists locally and on `origin` (`git ls-remote --tags origin | tail`).
   - Print the new version, the tag, and the GitHub release URL.
   - Remind the user about any downstream deploy pipeline triggered by the tag.

## Guardrails

- Never release from a branch other than `main`.
- Never release if the working tree is dirty or if local `main` is ahead/behind `origin/main`.
- Do not edit `CHANGELOG.md` or `package.json` by hand — `release-it` owns those.
- Do not push tags manually — `release-it` pushes them.
- If anything fails mid-release, do NOT attempt to "fix forward" without checking: report the state (git log, tags, remote tags) and ask the user.
