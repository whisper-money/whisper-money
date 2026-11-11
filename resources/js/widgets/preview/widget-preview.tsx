import {
    type WidgetHeight,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import { useEffect, useState, type ComponentType } from 'react';
import {
    toWidgetRuntimeError,
    WidgetErrorBoundary,
    WidgetErrorState,
    type WidgetRuntimeError,
} from '../runtime/widget-error-boundary';
import { ensureWidgetRuntime } from '../runtime/widget-runtime';
import { WidgetSdkProvider, type WidgetAccount } from '../sdk/context';
import { setDefaultMoneyLocale } from '../sdk/format-money';
import { linkWidgetModuleInline } from './link-inline';

/** What create_widget, update_widget and get_widget put in their `_meta`. */
export interface WidgetPreviewPayload {
    widget_id: string;
    name: string;
    revision: number;
    js: string;
    size: { width: WidgetWidth; height: WidgetHeight };
    /** Each call's body, by the key the manifest gave it. */
    data: Record<string, unknown>;
    failures: { key: string; status: number; message: string }[];
    currency: string;
    locale: string;
    accounts: Record<string, WidgetAccount>;
}

type LoadState =
    | { status: 'loading' }
    | { status: 'ready'; Widget: ComponentType }
    | { status: 'failed'; message: string };

/** The dashboard cell heights: `l` is two `m` rows and the gap between them. */
const HEIGHTS: Record<WidgetHeight, string> = { m: '16rem', l: '33.5rem' };

/** About the width of each fraction on a desktop dashboard. */
const MAX_WIDTHS: Record<WidgetWidth, string> = {
    '1/3': '25rem',
    '1/2': '39rem',
    '1/1': '100%',
};

const REGISTRY_KEY = Symbol.for('whisper-money.widget-modules');

/** A module that has not reported its component by then is treated as failed. */
const LOAD_TIMEOUT_MS = 15_000;

let loads = 0;

type PreviewGlobal = typeof globalThis & {
    [key: symbol]: unknown;
};

/**
 * Evaluate the compiled widget as an inline module script (see
 * link-inline.ts) and resolve to its component.
 */
async function loadInline(js: string): Promise<ComponentType> {
    await ensureWidgetRuntime();

    const global = globalThis as PreviewGlobal;
    const registry = global[REGISTRY_KEY] as Parameters<
        typeof linkWidgetModuleInline
    >[1];
    const reportKey = `whisper-money.widget-preview.${++loads}`;
    const source = linkWidgetModuleInline(js, registry, reportKey);

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        // A module that throws at its top level never reports back.
        const timeout = setTimeout(
            () => reject(new Error('The widget module did not load.')),
            LOAD_TIMEOUT_MS,
        );

        global[Symbol.for(reportKey)] = (component: unknown) => {
            clearTimeout(timeout);

            if (typeof component === 'function') {
                resolve(component as ComponentType);
            } else {
                reject(
                    new Error(
                        'The widget module has no default export component.',
                    ),
                );
            }
        };
        script.type = 'module';
        script.textContent = source;
        script.addEventListener('error', () =>
            reject(new Error('The widget module failed to load.')),
        );
        document.head.appendChild(script);
    });
}

/**
 * One widget drawn as the dashboard draws it, with the same SDK, error
 * boundary and stylesheet, from the payload a widget tool returned.
 */
export function WidgetPreview({
    payload,
    theme,
    onError,
}: {
    payload: WidgetPreviewPayload;
    theme: 'light' | 'dark';
    onError: (error: WidgetRuntimeError) => void;
}) {
    const [state, setState] = useState<LoadState>({ status: 'loading' });
    const [failure] = payload.failures;

    useEffect(() => {
        let current = true;

        setDefaultMoneyLocale(payload.locale);
        loadInline(payload.js).then(
            (Widget) => current && setState({ status: 'ready', Widget }),
            (error: unknown) => {
                if (!current) {
                    return;
                }

                const runtimeError = toWidgetRuntimeError('load', error);

                setState({ status: 'failed', message: runtimeError.message });
                onError(runtimeError);
            },
        );

        return () => {
            current = false;
        };
        // A new module is a new widget; the callback is read when it settles.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payload.js, payload.locale]);

    return (
        <figure className="flex flex-col gap-2 p-2">
            <figcaption className="flex items-baseline justify-between gap-2 text-xs text-muted-foreground">
                <span className="truncate font-medium text-foreground">
                    {payload.name}
                </span>
                <span>
                    {payload.size.width} · {payload.size.height} · rev{' '}
                    {payload.revision}
                </span>
            </figcaption>
            <div
                className="wm-widget w-full min-w-0 overflow-hidden rounded-xl border bg-card text-card-foreground"
                style={{
                    height: HEIGHTS[payload.size.height],
                    maxWidth: MAX_WIDTHS[payload.size.width],
                }}
            >
                {failure !== undefined && (
                    <WidgetErrorState
                        message={`${failure.key}: ${failure.message}`}
                    />
                )}
                {failure === undefined && state.status === 'loading' && (
                    <div
                        aria-busy="true"
                        className="h-full w-full animate-pulse bg-muted"
                    />
                )}
                {failure === undefined && state.status === 'failed' && (
                    <WidgetErrorState message={state.message} />
                )}
                {failure === undefined && state.status === 'ready' && (
                    <WidgetErrorBoundary resetKey={payload} onError={onError}>
                        <WidgetSdkProvider
                            value={{
                                data: payload.data,
                                accounts: payload.accounts,
                                env: {
                                    theme,
                                    width: payload.size.width,
                                    height: payload.size.height,
                                    isCompact: payload.size.width === '1/3',
                                    privacyMode: false,
                                    currency: payload.currency,
                                    locale: payload.locale,
                                },
                            }}
                        >
                            <state.Widget />
                        </WidgetSdkProvider>
                    </WidgetErrorBoundary>
                )}
            </div>
        </figure>
    );
}
