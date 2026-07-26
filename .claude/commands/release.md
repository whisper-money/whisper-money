---
description: Cut a new release (patch by default) with release-it, via PR because main is protected
argument-hint: "[patch|minor|major]"
allowed-tools: Bash(git *), Bash(gh *), Bash(bun run release*), Bash(awk *), Read
---

Cut a new release. Bump type: `$1` — if empty, use **patch**.

`main` is protected (PR required + 5 status checks), so `bun run release` on its own
always fails at the push step. Do it via a release branch and a PR instead.

## Steps

1. **Check preconditions.** `git status --short` must be empty and you must be on
   `main`. Then `git fetch origin && git pull --ff-only`. Stop and tell the user if
   the working dir is dirty or `main` can't fast-forward.

2. **Work out the next version** from `package.json` and the bump type, then create
   the branch and push it so release-it has an upstream (it errors out without one):

   ```bash
   git checkout -b release/vX.Y.Z
   git push -u origin release/vX.Y.Z
   ```

3. **Bump + changelog.** Tag, push and GitHub release are all disabled here — they
   happen after the merge, on `main`:

   ```bash
   bun run release -- <patch|minor|major> --ci --no-git.push --no-git.tag --no-github.release
   ```

   This bumps `package.json`, regenerates `CHANGELOG.md` (conventional-changelog,
   angular preset), runs `scripts/enrich-changelog.js` and commits
   `chore: release vX.Y.Z`. Verify the commit touches only `package.json`,
   `package-lock.json` and `CHANGELOG.md`, then `git push`.

4. **Open the PR** against `main`. Title must be `chore: release vX.Y.Z` (the
   conventional-commit check is required). Body in English: what was bumped, and a
   note that the tag and GitHub release land after the merge.

5. **Wait for CI**: `gh pr checks <n> --watch --interval 30`. Everything must pass —
   `skipping` jobs are fine. If something fails, fix it or report back; do not merge red.

6. **Merge**: `gh pr merge <n> --squash --delete-branch`, then
   `git checkout main && git pull --ff-only`.

7. **Tag and publish** on the merged commit:

   ```bash
   git tag -a vX.Y.Z -m "Release vX.Y.Z"
   git push origin vX.Y.Z
   awk '/^## \[X\.Y\.Z\]/{f=1} f && /^## \[/ && !/^## \[X\.Y\.Z\]/{exit} f' CHANGELOG.md > notes.md
   gh release create vX.Y.Z --title "vX.Y.Z" --notes-file notes.md
   ```

   Write `notes.md` to the scratchpad dir, not the repo. Finish by giving the user
   the release URL.

## If release-it fails mid-run

It rolls back on its own (resets the commit, deletes the local tag, unpushes a
remote tag). Confirm with `git status`, `git log --oneline -1` and
`git ls-remote --tags origin 'v*'` before retrying — don't clean up by hand unless
something actually survived.
