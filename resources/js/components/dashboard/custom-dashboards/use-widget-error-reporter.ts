import { store as storeWidgetError } from '@/actions/App/Http/Controllers/Widgets/WidgetErrorController';
import { getCsrfToken } from '@/lib/csrf';
import { trackWidgetRuntimeError } from '@/lib/custom-dashboards-telemetry';
import { getStorage } from '@/lib/safe-storage';
import { type UserWidgetDefinition } from '@/types/dashboard';
import { type WidgetRuntimeError } from '@/widgets/runtime/widget-error-boundary';
import { useCallback, useState } from 'react';

/** A runtime error caught on this page, with the revision that raised it. */
export interface CaughtWidgetError extends WidgetRuntimeError {
    revision: number;
    caughtAt: string;
}

/** The error the details show: the one caught on this page, else the stored one. */
export interface ShownWidgetError {
    revision: number;
    phase: 'load' | 'render';
    message: string;
    at: string | null;
}

/**
 * The newest error of the widget's current revision. One caught on this page
 * wins: the stored one is only as fresh as the page's last load.
 */
export function currentWidgetError(
    definition: UserWidgetDefinition,
    caught: CaughtWidgetError | undefined,
): ShownWidgetError | null {
    if (caught !== undefined && caught.revision === definition.revision) {
        return { ...caught, at: caught.caughtAt };
    }

    const stored = definition.last_error;

    if (stored !== null && stored.revision === definition.revision) {
        return { ...stored, at: definition.last_error_at };
    }

    return null;
}

const REPORTED_KEY = 'wm:reported-widget-errors';

/**
 * The revisions whose error this tab already sent, by `id@revision`. Kept in
 * sessionStorage for the tab's session (reloads included), so a widget that
 * throws on every render, or every time the dashboard opens, is stored once.
 * Without storage it lasts as long as the page.
 */
const reported = {
    keys: null as Set<string> | null,

    all(): Set<string> {
        if (this.keys === null) {
            try {
                const stored = getStorage('session')?.getItem(REPORTED_KEY);
                this.keys = new Set(stored ? JSON.parse(stored) : []);
            } catch {
                this.keys = new Set();
            }
        }

        return this.keys;
    },

    has(key: string): boolean {
        return this.all().has(key);
    },

    add(key: string): void {
        this.all().add(key);
        this.persist();
    },

    delete(key: string): void {
        this.all().delete(key);
        this.persist();
    },

    persist(): void {
        try {
            getStorage('session')?.setItem(
                REPORTED_KEY,
                JSON.stringify([...this.all()]),
            );
        } catch {
            // Storage full or blocked: the page still remembers.
        }
    },
};

/** Forgets what was sent; for tests. */
export function resetReportedWidgetErrors(): void {
    reported.keys = new Set();
    getStorage('session')?.removeItem(REPORTED_KEY);
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
            trackWidgetRuntimeError(error.phase);
            // A report that did not land is sent again the next time the
            // widget fails (the next dashboard load, at the latest).
            const forget = () => {
                reported.delete(key);
            };
            send(definition, error).then((response) => {
                if (!response.ok) {
                    forget();
                }
            }, forget);
        },
        [canStore],
    );

    return { errors, report };
}
