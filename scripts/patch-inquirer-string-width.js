/**
 * Patch script to fix ESM/CJS interop issue with string-width in @inquirer/core.
 *
 * @inquirer/core bundles wrap-ansi@6 (CJS) which requires string-width@4 (CJS).
 * However, the top-level string-width is v8 (ESM-only), causing:
 *   ERROR stringWidth is not a function
 *
 * This script installs CJS-compatible versions of string-width and its
 * dependencies into @inquirer/core's nested node_modules after each install.
 */

import { execSync } from 'child_process';
import { existsSync, mkdirSync } from 'fs';
import { resolve } from 'path';

const targetDir = resolve(
    import.meta.dirname,
    '../node_modules/@inquirer/core/node_modules',
);

const packages = [
    { name: 'string-width', version: '4.2.3' },
    { name: 'strip-ansi', version: '6.0.1' },
    { name: 'ansi-regex', version: '5.0.1' },
    { name: 'is-fullwidth-code-point', version: '3.0.0' },
];

for (const pkg of packages) {
    const dest = resolve(targetDir, pkg.name);
    if (existsSync(dest)) {
        continue;
    }

    console.log(`Patching ${pkg.name}@${pkg.version} into @inquirer/core...`);
    mkdirSync(dest, { recursive: true });

    const tarball = `${pkg.name}-${pkg.version}.tgz`;
    execSync(`npm pack ${pkg.name}@${pkg.version} --quiet`, {
        cwd: dest,
        stdio: 'pipe',
    });
    execSync(`tar -xzf ${tarball} --strip-components=1`, {
        cwd: dest,
        stdio: 'pipe',
    });
    execSync(`rm ${tarball}`, { cwd: dest, stdio: 'pipe' });
}

console.log('Patch applied.');
