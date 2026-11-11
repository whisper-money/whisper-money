import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { compile } from 'tailwindcss';

const root = path.resolve(
    fileURLToPath(new URL('.', import.meta.url)),
    '../..',
);
const require = createRequire(import.meta.url);

export const WIDGET_CSS_PATH = path.join(root, 'resources/css/widgets.css');
export const WIDGET_CLASSES_PATH = path.join(
    root,
    'resources/js/widgets/widget-classes.json',
);

/**
 * Compile resources/css/widgets.css with Tailwind exactly as the Vite build
 * does (no content scanning: every class comes from its `@source inline`), so
 * the class list is read from the CSS that actually ships.
 */
export async function compileWidgetCss(): Promise<string> {
    const css = await readFile(WIDGET_CSS_PATH, 'utf8');
    const compiler = await compile(css, {
        base: path.dirname(WIDGET_CSS_PATH),
        loadStylesheet: async (id: string, base: string) => {
            const file = id.startsWith('.')
                ? path.resolve(base, id)
                : require.resolve(id, { paths: [base] });

            return {
                path: file,
                base: path.dirname(file),
                content: await readFile(file, 'utf8'),
            };
        },
    });

    return compiler.build([]);
}

/**
 * The class names the widget stylesheet defines, read off its selectors. Every
 * utility is emitted as a top-level `.class { … }` rule nested in
 * `.wm-widget`, with Tailwind's escaping (`\:` for variants, `\/` for
 * fractions, `\.` for decimals).
 */
export function classNamesIn(css: string): string[] {
    const names = new Set<string>();

    for (const match of css.matchAll(/^ {2,4}\.((?:\\.|[^\s{\\])+) \{$/gm)) {
        names.add(match[1].replace(/\\(.)/g, '$1'));
    }

    return [...names].sort();
}
