import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import * as Sentry from '@sentry/react';
import axios from 'axios';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import {
    CircleCheckIcon,
    InfoIcon,
    Loader2Icon,
    OctagonXIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { StrictMode, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';
import { EncryptionKeyProvider } from './contexts/encryption-key-context';
import { PrivacyModeProvider } from './contexts/privacy-mode-context';
import { SyncProvider } from './contexts/sync-context';
import { initializeTheme } from './hooks/use-appearance';
import { initializeChartColorScheme } from './hooks/use-chart-color-scheme';
import { installChunkLoadRecovery } from './lib/chunk-load-recovery';
import { initializePostHog } from './lib/posthog';
import {
    isChunkLoadErrorEvent,
    isFacebookInAppBrowserJavaBridgeNoise,
    isPostMessageDataCloneNoise,
} from './lib/sentry';
import { showSubscriptionPaymentIssueToast } from './lib/subscription-payment-issue-toast';
import type { ExpiredBankingConnectionNotification, SharedData } from './types';
import { __, setTranslations } from './utils/i18n';

installChunkLoadRecovery();

Sentry.init({
    dsn: import.meta.env.SENTRY_LARAVEL_DSN,
    environment: import.meta.env.MODE,
    integrations: [],
    tracesSampleRate: 0,
    sendDefaultPii: true,
    beforeSend(event) {
        if (
            isChunkLoadErrorEvent(event) ||
            isPostMessageDataCloneNoise(event) ||
            isFacebookInAppBrowserJavaBridgeNoise(event)
        ) {
            return null;
        }

        return event;
    },
    enabled:
        import.meta.env.PROD && Boolean(import.meta.env.SENTRY_LARAVEL_DSN),
});

initializePostHog();

// Initialize theme before creating the app so progress bar color is correct
initializeTheme();
initializeChartColorScheme();

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
let hasAttemptedTimezoneBackfill = false;
const notifiedExpiredConnectionIds = new Set<string>();

function showExpiredConnectionsToast(
    expiredConnections: ExpiredBankingConnectionNotification[] | undefined,
): void {
    if (!expiredConnections || expiredConnections.length === 0) {
        return;
    }

    const newExpiredConnections = expiredConnections.filter(
        (connection) => !notifiedExpiredConnectionIds.has(connection.id),
    );

    if (newExpiredConnections.length === 0) {
        return;
    }

    expiredConnections.forEach((connection) => {
        notifiedExpiredConnectionIds.add(connection.id);
    });

    const firstConnection = expiredConnections[0];
    const count = expiredConnections.length;

    toast.error(
        count === 1
            ? __('Your :provider connection has expired.', {
                  provider: firstConnection.aspsp_name,
              })
            : __('You have :count expired bank connections.', {
                  count,
              }),
        {
            description: __('Reconnect to resume automatic syncing.'),
            duration: Infinity,
            action: {
                label: __('Reconnect'),
                onClick: () => {
                    window.location.href = firstConnection.reconnect_url;
                },
            },
        },
    );
}

function ExpiredConnectionsToast({
    initialExpiredConnections,
}: {
    initialExpiredConnections: ExpiredBankingConnectionNotification[];
}) {
    useEffect(() => {
        showExpiredConnectionsToast(initialExpiredConnections);

        return router.on('navigate', (event) => {
            const pageProps = event.detail.page.props as unknown as SharedData;
            showExpiredConnectionsToast(pageProps.expiredBankingConnections);
        });
    }, [initialExpiredConnections]);

    return null;
}

// Determine progress bar color based on current theme
const getProgressBarColor = () => {
    const isDark = document.documentElement.classList.contains('dark');
    return isDark ? '#EEE' : '#4B5563'; // gray-400 for dark mode, gray-600 for light mode
};

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);
        const initialPageProps = props.initialPage?.props as
            | Partial<SharedData>
            | undefined;
        const initialUser = initialPageProps?.auth?.user ?? null;
        const initialIsAuthenticated = Boolean(initialUser);
        const hasEncryptionSetup =
            (initialPageProps?.hasEncryptionSetup as boolean) ?? false;
        const hasEncryptedAccounts =
            (initialPageProps?.hasEncryptedAccounts as boolean) ?? false;
        const hasEncryptedTransactions =
            (initialPageProps?.hasEncryptedTransactions as boolean) ?? false;
        const initialExpiredConnections =
            (initialPageProps?.expiredBankingConnections as
                | ExpiredBankingConnectionNotification[]
                | undefined) ?? [];
        const initialSubscriptionPaymentIssue =
            initialPageProps?.subscriptionPaymentIssue;

        const syncUserTimezone = async (pageProps?: Partial<SharedData>) => {
            const user = pageProps?.auth?.user ?? null;
            const detectedTimezone =
                Intl.DateTimeFormat().resolvedOptions().timeZone;

            if (
                hasAttemptedTimezoneBackfill ||
                !user ||
                user.timezone ||
                !detectedTimezone
            ) {
                return;
            }

            hasAttemptedTimezoneBackfill = true;

            try {
                await axios.patch('/settings/timezone', {
                    timezone: detectedTimezone,
                });
            } catch {
                hasAttemptedTimezoneBackfill = false;
            }
        };

        // Initialize translations from server-rendered page data
        setTranslations(
            (initialPageProps?.translations as Record<string, string>) ?? {},
        );

        // Keep translations in sync on every Inertia navigation
        router.on('navigate', (event) => {
            const pageProps = event.detail.page.props as unknown as SharedData;
            setTranslations(
                (pageProps?.translations as Record<string, string>) ?? {},
            );

            showSubscriptionPaymentIssueToast(
                pageProps.subscriptionPaymentIssue,
            );

            void syncUserTimezone(pageProps);
        });

        showSubscriptionPaymentIssueToast(initialSubscriptionPaymentIssue);

        void syncUserTimezone(initialPageProps);

        root.render(
            <StrictMode>
                <EncryptionKeyProvider
                    hasEncryptionSetup={
                        hasEncryptionSetup &&
                        (hasEncryptedAccounts || hasEncryptedTransactions)
                    }
                >
                    <PrivacyModeProvider>
                        <SyncProvider
                            initialIsAuthenticated={initialIsAuthenticated}
                            initialUser={initialUser}
                        >
                            <App {...props} />
                            <ExpiredConnectionsToast
                                initialExpiredConnections={
                                    initialExpiredConnections
                                }
                            />
                            <Toaster
                                richColors
                                mobileOffset={{ bottom: '110px' }}
                                icons={{
                                    success: (
                                        <CircleCheckIcon className="size-4" />
                                    ),
                                    info: <InfoIcon className="size-4" />,
                                    warning: (
                                        <TriangleAlertIcon className="size-4" />
                                    ),
                                    error: <OctagonXIcon className="size-4" />,
                                    loading: (
                                        <Loader2Icon className="size-4 animate-spin" />
                                    ),
                                }}
                            />
                        </SyncProvider>
                    </PrivacyModeProvider>
                </EncryptionKeyProvider>
            </StrictMode>,
        );
    },
    progress: {
        color: getProgressBarColor(),
    },
});
