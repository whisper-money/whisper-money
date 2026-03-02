/**
 * Orphan component detection
 *
 * Every file under resources/js/components/ must be imported (directly or via
 * a barrel re-export) somewhere in the codebase. If a component is never
 * referenced it is dead code and this test will fail.
 *
 * How it works
 * ------------
 * 1. Collect all component files (the candidates).
 * 2. Collect all source files that may consume components (pages, layouts,
 *    hooks, contexts, lib, app entry points, and other components that import
 *    siblings).
 * 3. For each candidate, check every consumer file for a reference using:
 *      a) The @/ alias path (e.g. "@/components/foo/bar")
 *      b) The relative path from that consumer's directory to the component
 *         (e.g. "./bar", "../foo/bar", "./import-balances/bar")
 *      c) The barrel directory path if an index.* exists in the component's
 *         top-level subdirectory (e.g. "@/components/charts")
 * 4. Fail if no consumer references the component.
 *
 * Exclusions
 * ----------
 * - index.ts / index.tsx barrel files are skipped as standalone candidates.
 * - *.test.ts / *.test.tsx files are excluded from both candidates and
 *   consumers so test imports don't hide real orphans.
 */

import { readFileSync, readdirSync, statSync } from 'fs';
import { dirname, extname, join, relative, resolve } from 'path';
import { describe, expect, it } from 'vitest';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// __dirname is resources/js/lib — go up one level to reach resources/js
const jsRoot = resolve(__dirname, '..');
const componentsRoot = join(jsRoot, 'components');

/** Recursively collect all files matching the given extensions. */
function collectFiles(dir: string, extensions: string[]): string[] {
    const results: string[] = [];

    for (const entry of readdirSync(dir)) {
        const fullPath = join(dir, entry);
        const stat = statSync(fullPath);

        if (stat.isDirectory()) {
            results.push(...collectFiles(fullPath, extensions));
        } else if (extensions.includes(extname(entry))) {
            results.push(fullPath);
        }
    }

    return results;
}

/** Return the `@/...` alias import path (no extension) for an absolute path. */
function toAliasPath(absolutePath: string): string {
    const rel = relative(jsRoot, absolutePath);
    return '@/' + rel.replace(/\.(tsx?|jsx?)$/, '');
}

/**
 * Return all import strings that would constitute a valid reference to
 * `componentFile` from within `consumerFile`.
 */
function importStrings(componentFile: string, consumerFile: string): string[] {
    const aliasPath = toAliasPath(componentFile); // "@/components/foo/bar"
    const consumerDir = dirname(consumerFile);

    // Relative path from consumer's directory to component (no extension)
    const rel = relative(consumerDir, componentFile).replace(
        /\.(tsx?|jsx?)$/,
        '',
    );
    // Ensure it starts with ./ or ../
    const relPath = rel.startsWith('.') ? rel : './' + rel;

    const variants = (p: string) => [
        `'${p}'`,
        `"${p}"`,
        `'${p}.ts'`,
        `"${p}.ts"`,
        `'${p}.tsx'`,
        `"${p}.tsx"`,
    ];

    return [...variants(aliasPath), ...variants(relPath)];
}

// ---------------------------------------------------------------------------
// Build the candidate list (component files, excluding barrel index files)
// ---------------------------------------------------------------------------

const allComponentFiles = collectFiles(componentsRoot, ['.ts', '.tsx']).filter(
    (f) => !f.endsWith('.test.ts') && !f.endsWith('.test.tsx'),
);

const candidateFiles = allComponentFiles.filter((f) => {
    const base = f.split('/').pop()!;
    return base !== 'index.ts' && base !== 'index.tsx';
});

// ---------------------------------------------------------------------------
// Build consumer list — everything in resources/js except test files
// ---------------------------------------------------------------------------

const allSourceFiles = collectFiles(jsRoot, ['.ts', '.tsx']).filter(
    (f) => !f.endsWith('.test.ts') && !f.endsWith('.test.tsx'),
);

// Pre-read all consumer file contents paired with their path
const consumers: { path: string; content: string }[] = allSourceFiles.map(
    (f) => ({ path: f, content: readFileSync(f, 'utf-8') }),
);

// ---------------------------------------------------------------------------
// Pre-compute barrel directories so we can accept directory-level imports
// ---------------------------------------------------------------------------

const barrelDirs = new Set<string>();
for (const f of allComponentFiles) {
    const base = f.split('/').pop()!;
    if (base === 'index.ts' || base === 'index.tsx') {
        barrelDirs.add(dirname(f));
    }
}

// ---------------------------------------------------------------------------
// Test
// ---------------------------------------------------------------------------

describe('Orphan component detection', () => {
    it('every component must be imported somewhere in the codebase', () => {
        const orphans: string[] = [];

        for (const componentFile of candidateFiles) {
            const componentDir = dirname(componentFile);

            const isUsed = consumers.some(({ path: consumerPath, content }) => {
                // Check alias + relative import patterns for this consumer
                const patterns = importStrings(componentFile, consumerPath);

                // If the component sits inside a barrel directory, also accept
                // an import of that directory (alias or relative)
                if (barrelDirs.has(componentDir)) {
                    const barrelAlias = toAliasPath(
                        join(componentDir, 'index'),
                    ).replace(/\/index$/, '');
                    const relBarrel = relative(
                        dirname(consumerPath),
                        componentDir,
                    );
                    const relBarrelPath = relBarrel.startsWith('.')
                        ? relBarrel
                        : './' + relBarrel;

                    patterns.push(
                        `'${barrelAlias}'`,
                        `"${barrelAlias}"`,
                        `'${relBarrelPath}'`,
                        `"${relBarrelPath}"`,
                    );
                }

                return patterns.some((p) => content.includes(p));
            });

            if (!isUsed) {
                orphans.push(toAliasPath(componentFile));
            }
        }

        if (orphans.length > 0) {
            const list = orphans.map((o) => `  - ${o}`).join('\n');
            expect.fail(
                `Found ${orphans.length} orphan component(s) that are never imported:\n\n${list}\n\nRemove unused components or add them to the codebase.`,
            );
        }
    });
});
