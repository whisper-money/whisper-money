import { type WidgetSize } from '@/components/dashboard/widgets/types';
import { Skeleton } from '@/components/ui/skeleton';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useDocumentColorScheme } from '@/hooks/use-document-color-scheme';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type Account } from '@/types/account';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import {
    type ComponentType,
    useEffect,
    useLayoutEffect,
    useMemo,
    useState,
} from 'react';
import { loadWidgetModule } from '../load-widget-module';
import {
    type WidgetAccount,
    WidgetSdkProvider,
    type WidgetSdkValue,
} from '../sdk/context';
import { beginWidgetGesture, endWidgetGesture } from '../sdk/download';
import { setDefaultMoneyLocale } from '../sdk/format-money';
import { type UserWidgetProps } from './user-widget';
import {
    toWidgetRuntimeError,
    WidgetErrorBoundary,
    WidgetErrorState,
    type WidgetRuntimeError,
} from './widget-error-boundary';
import { ensureWidgetRuntime } from './widget-runtime';

/** A module that has not loaded by then is treated as failed. */
const LOAD_TIMEOUT_MS = 15_000;

type LoadState =
    | { status: 'loading' }
    | { status: 'ready'; Widget: ComponentType }
    | { status: 'failed'; error: WidgetRuntimeError };

/**
 * The part of `<UserWidget>` that needs the widget runtime, split into its own
 * chunk so the page that draws user widgets downloads none of it until one
 * mounts (and shares no module with the SDK, which loads the same way).
 */
export default function UserWidgetFrame({
    js,
    data,
    size,
    onError,
    onSettled,
    className,
}: UserWidgetProps) {
    const [state, setState] = useState<LoadState>({ status: 'loading' });
    const sdk = useWidgetSdkValue(data, size);

    useLayoutEffect(() => {
        setDefaultMoneyLocale(sdk.env.locale);
    }, [sdk.env.locale]);

    useEffect(() => {
        let finished = false;
        const fail = (failure: WidgetRuntimeError, isWidgetError: boolean) => {
            if (finished) {
                return;
            }

            finished = true;
            setState({ status: 'failed', error: failure });
            // Only the widget's own failures are stored for its agent: a
            // runtime chunk that did not download is not something it can fix.
            if (isWidgetError) {
                onError?.(failure);
            }
            onSettled?.();
        };
        const timeout = setTimeout(
            () =>
                fail(
                    toWidgetRuntimeError(
                        'load',
                        new Error(__('The widget took too long to load.')),
                    ),
                    false,
                ),
            LOAD_TIMEOUT_MS,
        );

        ensureWidgetRuntime()
            .then(
                () =>
                    loadWidgetModule(js).then(
                        (Widget) => {
                            if (!finished) {
                                finished = true;
                                setState({ status: 'ready', Widget });
                            }
                        },
                        (error: unknown) =>
                            fail(toWidgetRuntimeError('load', error), true),
                    ),
                (error: unknown) =>
                    fail(toWidgetRuntimeError('load', error), false),
            )
            .finally(() => clearTimeout(timeout));

        return () => {
            finished = true;
            clearTimeout(timeout);
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
            onClickCapture={(event) => beginWidgetGesture(event)}
            onClick={endWidgetGesture}
        >
            {state.status === 'loading' && (
                <Skeleton
                    aria-busy="true"
                    aria-label={__('Loading widget')}
                    className="h-full w-full rounded-none"
                />
            )}
            {state.status === 'failed' && (
                <WidgetErrorState message={state.error.message} />
            )}
            {state.status === 'ready' && (
                <WidgetErrorBoundary
                    resetKey={data}
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
