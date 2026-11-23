/**
 * Shared by the source guards (`lib/*-guard.test.ts`): tests that read every
 * file under resources/js and fail when one breaks a design-system rule,
 * unless an allowlist says why it may.
 *
 * Runs in Vitest only (it reads the disk with `fs`); nothing in the app
 * imports it.
 */

import { readFileSync, readdirSync, statSync } from 'fs';
import { join, relative, resolve } from 'path';

export interface ForbiddenPattern {
    pattern: RegExp;
    /** Completes "<file> …" in the failure message. */
    what: string;
}

const jsRoot = resolve(__dirname, '..');

/** Wayfinder's generated (and git-ignored) route helpers. */
const GENERATED_DIRS = ['actions', 'routes', 'wayfinder'];

/** Marketing and legal pages have their own look; no guard covers them. */
export const MARKETING_PAGES: readonly string[] = [
    'components/landing/',
    'components/documentation/',
    'pages/documentation/',
    'pages/welcome.tsx',
    'pages/terms.tsx',
    'pages/privacy.tsx',
    'pages/comparison.tsx',
    'pages/roadmap.tsx',
    'pages/integrations.tsx',
];

function collectSourceFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);

        if (dir === jsRoot && GENERATED_DIRS.includes(entry)) {
            return [];
        }

        if (statSync(path).isDirectory()) {
            return collectSourceFiles(path);
        }

        const isSource = /\.(ts|tsx)$/.test(entry);
        const isTest = /\.test\.(ts|tsx)$/.test(entry);

        return isSource && !isTest ? [path] : [];
    });
}

/** A trailing slash in `allowed` allows a whole folder. */
function isAllowed(file: string, allowed: readonly string[]): boolean {
    return allowed.some((entry) =>
        entry.endsWith('/') ? file.startsWith(entry) : file === entry,
    );
}

/**
 * Every "<file> <what>" where a source file outside `allowed` (paths relative
 * to resources/js) matches one of `forbidden`.
 */
export function findViolations(
    forbidden: readonly ForbiddenPattern[],
    allowed: readonly string[],
): string[] {
    return collectSourceFiles(jsRoot)
        .map((path) => relative(jsRoot, path))
        .filter((file) => !isAllowed(file, allowed))
        .flatMap((file) => {
            const source = readFileSync(join(jsRoot, file), 'utf8');

            return forbidden
                .filter(({ pattern }) => pattern.test(source))
                .map(({ what }) => `${file} ${what}`);
        });
}

/** The allowlist entries that no longer exist, so they can be deleted. */
export function missingPaths(allowed: readonly string[]): string[] {
    return allowed.filter((entry) => {
        try {
            statSync(join(jsRoot, entry));

            return false;
        } catch {
            return true;
        }
    });
}
