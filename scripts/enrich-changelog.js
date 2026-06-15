/**
 * Enriches the latest CHANGELOG.md release section with PR author credits.
 *
 * Squash-merges collapse every commit author to the repo owner, so the real
 * contributor is not in git history. Instead we read the PR number already
 * present in each entry (`(#531)`), resolve the GitHub handle via the `gh`
 * CLI, and append `by [@handle](https://github.com/handle)` to the line.
 *
 * Only the most recent release section (first `## [` heading up to the next
 * one) is processed, so historical sections stay untouched. The pass is
 * idempotent: already-credited lines are skipped. Failures to resolve an
 * author (e.g. `gh` missing or unauthenticated) are non-fatal and the entry
 * is left as-is, so a release is never blocked by this step.
 *
 * Wired into release-it via the `after:bump` hook; can also be run manually:
 *   node scripts/enrich-changelog.js
 */

import { execFileSync } from 'child_process';
import { readFileSync, writeFileSync } from 'fs';
import { resolve } from 'path';
import process from 'process';

const FILE = resolve(import.meta.dirname, '../CHANGELOG.md');

const lines = readFileSync(FILE, 'utf8').split('\n');

const sectionStart = lines.findIndex((line) => line.startsWith('## ['));
if (sectionStart === -1) {
    console.log('enrich-changelog: no release section found, nothing to do.');
    process.exit(0);
}
let sectionEnd = lines.findIndex(
    (line, index) => index > sectionStart && line.startsWith('## ['),
);
if (sectionEnd === -1) {
    sectionEnd = lines.length;
}

const PR_LINK = /\[#(\d+)\]\([^)]*\/issues\/\d+\)/;
const COMMIT_LINK = /\(\[[0-9a-f]{7,40}\]\([^)]*\/commit\/[^)]*\)\)/;

const authorByPr = new Map();

function resolveAuthor(pr) {
    if (authorByPr.has(pr)) {
        return authorByPr.get(pr);
    }

    let handle = null;
    try {
        handle =
            execFileSync(
                'gh',
                ['pr', 'view', pr, '--json', 'author', '--jq', '.author.login'],
                { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] },
            ).trim() || null;
    } catch {
        handle = null;
    }

    authorByPr.set(pr, handle);
    return handle;
}

let enriched = 0;
for (let index = sectionStart; index < sectionEnd; index++) {
    const line = lines[index];

    if (!line.startsWith('* ') || / by \[@/.test(line)) {
        continue;
    }

    const prMatch = line.match(PR_LINK);
    if (!prMatch) {
        continue;
    }

    const handle = resolveAuthor(prMatch[1]);
    if (!handle) {
        continue;
    }

    const credit = ` by [@${handle}](https://github.com/${handle})`;
    const commitMatch = line.match(COMMIT_LINK);
    if (commitMatch) {
        const insertAt = commitMatch.index + commitMatch[0].length;
        lines[index] = line.slice(0, insertAt) + credit + line.slice(insertAt);
    } else {
        lines[index] = line + credit;
    }

    enriched++;
}

if (enriched > 0) {
    writeFileSync(FILE, lines.join('\n'));
}

console.log(`enrich-changelog: ${enriched} ${enriched === 1 ? 'entry' : 'entries'} credited.`);
