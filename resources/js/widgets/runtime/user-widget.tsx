import { type WidgetSize } from '@/components/dashboard/widgets/types';
import { Skeleton } from '@/components/ui/skeleton';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useDocumentColorScheme } from '@/hooks/use-document-color-scheme';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type Account } from '@/types/account';
import { usePage } from '@inertiajs/react';
import { type ComponentType, useEffect, useMemo, useState } from 'react';
import { loadWidgetModule } from '../load-widget-module';
import {
    type WidgetAccount,
    WidgetSdkProvider,
    type WidgetSdkValue,
} from '../sdk/context';
import { beginWidgetGesture, endWidgetGesture } from '../sdk/download';
import { setDefaultMoneyLocale } from '../sdk/format-money';
import {
    toWidgetRuntimeError,
    WidgetErrorBoundary,
    WidgetErrorState,
    type WidgetRuntimeError,
} from './widget-error-boundary';
import { ensureWidgetRuntime } from './widget-runtime';

export interface UserWidgetProps {
    /** The compiled module, as the compiler stored it. */
    js: string;
    /** The data its manifest declared, already resolved by the gateway. */
    data: Readonly<Record<string, unknown>>;
    size: WidgetSize;
    /** A load or render failure, to be stored with the widget. */
    onError?: (error: WidgetRuntimeError) => void;
    /** The widget has drawn, or failed: safe mode counts these. */
    onSettled?: () => void;
    className?: string;
}

type LoadState =
    | { status: 'loading' }
    | { status: 'ready'; Widget: ComponentType }
    | { status: 'failed'; error: WidgetRuntimeError };

/**
 * A widget written by the user's agent, drawn in its dashboard cell. It loads
 * the compiled module on the app's own React (through the T7 loader), mounts
 * it inside `.wm-widget` (the scope of the precompiled widget CSS) and an
 * error boundary, and hands it data, theme, size, privacy mode and currency
 * through the SDK context.
 *
 * Renders nothing while the CustomDashboards flag is off.
 */
export function UserWidget(props: UserWidgetProps) {
    const { features } = usePage<SharedData>().props;

    if (!features?.customDashboards) {
        return null;
    }

    // A new module is a new widget: its error and load state start over.
    return <UserWidgetFrame key={props.js} {...props} />;
}

function UserWidgetFrame({
    js,
    data,
    size,
    onError,
    onSettled,
    className,
}: UserWidgetProps) {
    const [state, setState] = useState<LoadState>({ status: 'loading' });
    const sdk = useWidgetSdkValue(data, size);

    useEffect(() => {
        let cancelled = false;

        ensureWidgetRuntime()
            .then(() => loadWidgetModule(js))
            .then(
                (Widget) => {
                    if (!cancelled) {
                        setState({ status: 'ready', Widget });
                    }
                },
                (error: unknown) => {
                    if (cancelled) {
                        return;
                    }

                    const failure = toWidgetRuntimeError('load', error);

                    setState({ status: 'failed', error: failure });
                    onError?.(failure);
                    onSettled?.();
                },
            );

        return () => {
            cancelled = true;
        };
        // The callbacks are read when the load settles; a new one must not reload.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [js]);

    return (
        <div
            data-slot="user-widget"
            data-status={state.status}
            className={cn(
                // A size container needs a width of its own, or it collapses.
                'wm-widget h-full w-full min-w-0 overflow-hidden rounded-xl border bg-card text-card-foreground',
                className,
            )}
            onClickCapture={beginWidgetGesture}
            onClick={endWidgetGesture}
        >
            {state.status === 'loading' && (
                <Skeleton className="h-full w-full rounded-xl" />
            )}
            {state.status === 'failed' && (
                <WidgetErrorState message={state.error.message} />
            )}
            {state.status === 'ready' && (
                <WidgetErrorBoundary
                    onError={(error) => {
                        onError?.(error);
                        onSettled?.();
                    }}
                >
                    <WidgetSdkProvider value={sdk}>
                        <state.Widget />
                    </WidgetSdkProvider>
                    <Settled onSettled={onSettled} />
                </WidgetErrorBoundary>
            )}
        </div>
    );
}

/**
 * Its effect runs after the widget's own (siblings commit in order), so it
 * only fires once the widget has rendered and run its first effects.
 */
function Settled({ onSettled }: { onSettled?: () => void }) {
    useEffect(() => {
        onSettled?.();
        // Once per mount.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return null;
}

/** Everything the SDK hands one widget, read from the page it is on. */
function useWidgetSdkValue(
    data: Readonly<Record<string, unknown>>,
    size: WidgetSize,
): WidgetSdkValue {
    const { auth, accounts } = usePage<SharedData & { accounts?: Account[] }>()
        .props;
    const scheme = useDocumentColorScheme();
    const isMobile = useIsMobile();
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const locale = useLocale();
    const currency = auth.user?.currency_code ?? 'EUR';

    setDefaultMoneyLocale(locale);

    const widgetAccounts = useMemo(() => accountsById(accounts), [accounts]);

    return useMemo(
        () => ({
            data,
            accounts: widgetAccounts,
            env: {
                theme: scheme ?? 'light',
                width: size.width,
                height: size.height,
                isCompact: isMobile || size.width === '1/3',
                privacyMode: isPrivacyModeEnabled,
                currency,
                locale,
            },
        }),
        [
            data,
            widgetAccounts,
            scheme,
            size.width,
            size.height,
            isMobile,
            isPrivacyModeEnabled,
            currency,
            locale,
        ],
    );
}

function accountsById(
    accounts: Account[] | undefined,
): Readonly<Record<string, WidgetAccount>> {
    return Object.fromEntries(
        (accounts ?? []).map((account) => [
            account.id,
            {
                type: account.type,
                bankName: account.bank?.name ?? null,
                bankLogo: account.bank?.logo ?? null,
            },
        ]),
    );
}
