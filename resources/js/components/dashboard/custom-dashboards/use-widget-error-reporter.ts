import { store as storeWidgetError } from '@/actions/App/Http/Controllers/Widgets/WidgetErrorController';
import { getCsrfToken } from '@/lib/csrf';
import { type UserWidgetDefinition } from '@/types/dashboard';
import { type WidgetRuntimeError } from '@/widgets/runtime/widget-error-boundary';
import { useCallback, useState } from 'react';

/** A runtime error caught on this page, with the revision that raised it. */
export interface CaughtWidgetError extends WidgetRuntimeError {
    revision: number;
    caughtAt: string;
}

/**
 * The revisions whose error this tab already sent, by `id@revision`. Kept for
 * the whole page session (Inertia visits included), so a widget that throws
 * on every render, or every time the dashboard opens, is stored once.
 */
const reported = new Set<string>();

/** Forgets what was sent; for tests. */
export function resetReportedWidgetErrors(): void {
    reported.clear();
}

function send(definition: UserWidgetDefinition, error: WidgetRuntimeError) {
    return fetch(storeWidgetError.url(definition.id), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify({ revision: definition.revision, ...error }),
    });
}

/**
 * Keeps the runtime errors user widgets raise on the dashboard: each one is
 * stored with its widget once per revision (so the agent that wrote it can
 * read it and fix it), and remembered here so the widget's details show it
 * before the page reloads. `canStore` is off for shared accounts, whose
 * widgets nobody can fix.
 */
export function useWidgetErrorReporter(canStore: boolean): {
    errors: Readonly<Record<string, CaughtWidgetError>>;
    report: (
        definition: UserWidgetDefinition,
        error: WidgetRuntimeError,
    ) => void;
} {
    const [errors, setErrors] = useState<Record<string, CaughtWidgetError>>({});

    const report = useCallback(
        (definition: UserWidgetDefinition, error: WidgetRuntimeError) => {
            setErrors((previous) => ({
                ...previous,
                [definition.id]: {
                    ...error,
                    revision: definition.revision,
                    caughtAt: new Date().toISOString(),
                },
            }));

            const key = `${definition.id}@${definition.revision}`;

            if (!canStore || reported.has(key)) {
                return;
            }

            reported.add(key);
            // Best effort: the widget's cell already shows the error.
            send(definition, error).catch(() => undefined);
        },
        [canStore],
    );

    return { errors, report };
}
