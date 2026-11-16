/**
 * Validate and compile one widget. Reads `{"source": "<tsx>"}` from stdin and
 * writes `{"ok", "js", "errors", "timings"}` to stdout. It exits 0 whenever it
 * could judge the source, valid or not; anything else is a crash.
 *
 *   echo '{"source": "…"}' | bun scripts/widgets/compile-widget.ts
 *
 * Called by App\Services\Widgets\WidgetCompiler with a timeout.
 *
 * Bun macros run code at bundle time, and Bun 1.3 runs them even with
 * `--no-macros`. They never get here: a macro is an import with
 * `with { type: 'macro' }` of a local file, and the validator only lets
 * through named imports of the five allowed packages, without import
 * attributes. The build itself marks every package as external, takes no
 * plugins and is fed the source in memory, so there is no file next to it to
 * import.
 */
import { WIDGET_EXTERNALS } from '../../resources/js/widgets/validator/allowlist';
import { checkCompiledOutput } from '../../resources/js/widgets/validator/check-compiled-output';
import {
    validateWidget,
    type WidgetValidationError,
} from '../../resources/js/widgets/validator/validate-widget';

interface CompileResult {
    ok: boolean;
    js: string | null;
    errors: WidgetValidationError[];
    timings: { validateMs: number; buildMs: number };
}

const ENTRY = '/widget/widget.tsx';

async function compile(source: string): Promise<CompileResult> {
    const timings = { validateMs: 0, buildMs: 0 };
    const validationStart = performance.now();
    const errors = validateWidget(source);

    timings.validateMs = elapsed(validationStart);

    if (errors.length > 0) {
        return failure(errors, timings);
    }

    const buildStart = performance.now();
    const build = await Bun.build({
        entrypoints: [ENTRY],
        files: { [ENTRY]: source },
        target: 'browser',
        format: 'esm',
        packages: 'external',
        external: [...WIDGET_EXTERNALS],
        minify: true,
        define: { 'process.env.NODE_ENV': '"production"' },
        throw: false,
    });

    timings.buildMs = elapsed(buildStart);

    if (!build.success || build.outputs.length !== 1) {
        return failure(
            build.logs.map((log) => ({
                line: log.position?.line ?? 1,
                column: (log.position?.column ?? 0) + 1,
                code: 'build',
                message: log.message,
            })),
            timings,
        );
    }

    const js = await build.outputs[0].text();
    const problems = checkCompiledOutput(js);

    if (problems.length > 0) {
        return failure(
            problems.map((problem) => ({ line: 1, column: 1, ...problem })),
            timings,
        );
    }

    return { ok: true, js, errors: [], timings };
}

function failure(
    errors: WidgetValidationError[],
    timings: CompileResult['timings'],
): CompileResult {
    return { ok: false, js: null, errors, timings };
}

function elapsed(start: number): number {
    return Math.round((performance.now() - start) * 100) / 100;
}

function readSource(stdin: string): string {
    try {
        const input = JSON.parse(stdin) as { source?: unknown };

        if (typeof input.source === 'string') {
            return input.source;
        }
    } catch {
        // Reported below, like any other malformed input.
    }

    console.error('Expected {"source": "<tsx>"} on stdin.');
    process.exit(2);
}

process.stdout.write(
    JSON.stringify(await compile(readSource(await Bun.stdin.text()))),
);
