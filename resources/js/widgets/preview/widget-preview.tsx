import {
    type WidgetHeight,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import { cn } from '@/lib/utils';
import { type WidgetFrame } from '@/types/dashboard';
import { type ComponentType, useEffect, useRef, useState } from 'react';
import {
    isWidgetCarded,
    useWidgetCrash,
    WIDGET_CARD_CLASS_NAME,
} from '../runtime/widget-card';
import {
    toWidgetRuntimeError,
    WidgetErrorBoundary,
    WidgetErrorState,
    type WidgetRuntimeError,
} from '../runtime/widget-error-boundary';
import { ensureWidgetRuntime } from '../runtime/widget-runtime';
import { type WidgetAccount, WidgetSdkProvider } from '../sdk/context';
import { setDefaultMoneyLocale } from '../sdk/format-money';
import { linkWidgetModuleInline } from './link-inline';

/** What App\Services\Widgets\WidgetPreviewPayload builds. */
export interface WidgetPreviewPayload {
    widget_id: string;
    name: string;
    revision: number;
    js: string;
    size: { width: WidgetWidth; height: WidgetHeight };
    frame: WidgetFrame;
    /** Each call's body, by the key the manifest gave it; null when too large. */
    data: Record<string, unknown> | null;
    data_too_large: boolean;
    failures: { key: string; status: number; message: string }[];
    currency: string;
    locale: string;
    /** The user's chart palette, as the app sets it on `data-chart-color`. */
    chart_color_scheme: string;
    accounts: Record<string, WidgetAccount>;
}

type LoadState =
    | { status: 'loading' }
    | { status: 'ready'; js: string; Widget: ComponentType }
    | { status: 'failed'; message: string };

/**
 * The cell of a desktop dashboard (see grid-layout.ts): `m` is 16rem, `l` two
 * of those and the gap. The preview draws that fixed cell; on a phone the
 * dashboard lets the widget grow past it. `a` has no height: the widget's
 * content gives it one, as on the dashboard.
 */
const HEIGHTS: Record<WidgetHeight, string | undefined> = {
    m: '16rem',
    l: '33.5rem',
    a: undefined,
};

/** About the width of each fraction on a desktop dashboard. */
const MAX_WIDTHS: Record<WidgetWidth, string> = {
    '1/3': '25rem',
    '1/2': '39rem',
    '1/1': '100%',
};

/** Where registerWidgetModules() keeps the registry (load-widget-module.ts). */
const REGISTRY_KEY = Symbol.for('whisper-money.widget-modules');

/** A module that has not reported its component by then is treated as failed. */
const LOAD_TIMEOUT_MS = 15_000;

type PreviewGlobal = typeof globalThis & Record<symbol, unknown>;

let loads = 0;

/**
 * Evaluate the compiled widget as an inline module script (see
 * link-inline.ts) and resolve to its component.
 */
async function loadInline(js: string): Promise<ComponentType> {
    await ensureWidgetRuntime();

    const global = globalThis as PreviewGlobal;
    const reportKey = Symbol.for(`whisper-money.widget-preview.${++loads}`);
    const source = linkWidgetModuleInline(
        js,
        global[REGISTRY_KEY] as Parameters<typeof linkWidgetModuleInline>[1],
        reportKey.description!,
    );
    const script = document.createElement('script');
    let timeout: ReturnType<typeof setTimeout> | undefined;

    try {
        return await new Promise<ComponentType>((resolve, reject) => {
            // A module that throws at its top level never reports back.
            timeout = setTimeout(
                () => reject(new Error('The widget module did not load.')),
                LOAD_TIMEOUT_MS,
            );
            global[reportKey] = (component: unknown) =>
                typeof component === 'function'
                    ? resolve(component as ComponentType)
                    : reject(
                          new Error(
                              'The widget module has no default export component.',
                          ),
                      );
            script.type = 'module';
            script.textContent = source;
            script.addEventListener('error', () =>
                reject(new Error('The widget module failed to load.')),
            );
            document.head.appendChild(script);
        });
    } finally {
        clearTimeout(timeout);
        delete global[reportKey];
        script.remove();
    }
}

function useWidgetComponent(
    js: string,
    onError: (error: WidgetRuntimeError) => void,
): LoadState {
    const [state, setState] = useState<LoadState>({ status: 'loading' });
    const onErrorRef = useRef(onError);

    useEffect(() => {
        onErrorRef.current = onError;
    });

    useEffect(() => {
        let current = true;

        loadInline(js).then(
            (Widget) => current && setState({ status: 'ready', js, Widget }),
            (error: unknown) => {
                if (!current) {
                    return;
                }

                const runtimeError = toWidgetRuntimeError('load', error);

                setState({ status: 'failed', message: runtimeError.message });
                onErrorRef.current(runtimeError);
            },
        );

        return () => {
            current = false;
        };
    }, [js]);

    // A new revision is a new widget: never draw the old one with new data.
    return state.status === 'ready' && state.js !== js
        ? { status: 'loading' }
        : state;
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
    const state = useWidgetComponent(payload.js, onError);
    // Privacy mode lives in the user's browser, not on the server: let them
    // check here that the widget hides its figures.
    const [privacyMode, setPrivacyMode] = useState(false);
    const [failure] = payload.failures;
    const { crashed, markCrashed } = useWidgetCrash(payload);

    useEffect(() => {
        setDefaultMoneyLocale(payload.locale);
    }, [payload.locale]);

    useEffect(() => {
        // The app's use-chart-color-scheme: `neutral` is the stylesheet's default.
        if (payload.chart_color_scheme === 'neutral') {
            document.documentElement.removeAttribute('data-chart-color');
        } else {
            document.documentElement.setAttribute(
                'data-chart-color',
                payload.chart_color_scheme,
            );
        }
    }, [payload.chart_color_scheme]);

    let content;

    if (failure !== undefined) {
        content = (
            <WidgetErrorState message={`${failure.key}: ${failure.message}`} />
        );
    } else if (payload.data === null) {
        content = (
            <p className="flex h-full items-center justify-center p-4 text-center text-sm text-muted-foreground">
                This widget reads too much data to preview it here. Open it on
                the dashboard.
            </p>
        );
    } else if (state.status === 'loading') {
        content = (
            <div
                aria-busy="true"
                // An `a` cell has no height for it to fill.
                className="h-full min-h-40 w-full animate-pulse bg-muted"
            />
        );
    } else if (state.status === 'failed') {
        content = <WidgetErrorState message={state.message} />;
    } else {
        content = (
            <WidgetErrorBoundary
                resetKey={payload}
                onError={(error) => {
                    markCrashed();
                    onError(error);
                }}
            >
                <WidgetSdkProvider
                    value={{
                        data: payload.data,
                        accounts: payload.accounts,
                        env: {
                            theme,
                            width: payload.size.width,
                            height: payload.size.height,
                            isCompact: payload.size.width === '1/3',
                            privacyMode,
                            currency: payload.currency,
                            locale: payload.locale,
                        },
                    }}
                >
                    <state.Widget />
                </WidgetSdkProvider>
            </WidgetErrorBoundary>
        );
    }

    const showsWidget =
        failure === undefined &&
        payload.data !== null &&
        state.status === 'ready' &&
        !crashed;
    const carded = isWidgetCarded(payload.frame, showsWidget);

    return (
        <figure className="flex flex-col gap-2 p-2">
            <figcaption className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <span className="truncate font-medium text-foreground">
                    {payload.name}
                </span>
                <span className="flex shrink-0 items-center gap-3">
                    <label className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            checked={privacyMode}
                            onChange={(event) =>
                                setPrivacyMode(event.target.checked)
                            }
                        />
                        Hide amounts
                    </label>
                    <span>
                        {payload.size.width} · {payload.size.height} · rev{' '}
                        {payload.revision}
                    </span>
                </span>
            </figcaption>
            <div
                data-frame={carded ? 'card' : 'none'}
                className={cn(
                    'wm-widget w-full min-w-0 overflow-hidden',
                    carded && WIDGET_CARD_CLASS_NAME,
                )}
                style={{
                    height: HEIGHTS[payload.size.height],
                    maxWidth: MAX_WIDTHS[payload.size.width],
                }}
            >
                {content}
            </div>
        </figure>
    );
}
