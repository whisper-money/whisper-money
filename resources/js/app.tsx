import '../css/app.css';

import {
    createInertiaApp,
    router,
    type ResolvedComponent,
} from '@inertiajs/react';
import * as Sentry from '@sentry/react';
import axios from 'axios';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { toast } from 'sonner';
import { update as updateTimezone } from './actions/App/Http/Controllers/Settings/TimezoneController';
import { MedalUnlockedToast } from './components/achievements/medal-unlocked-toast';
import { UncategorizedToast } from './components/achievements/uncategorized-toast';
import { AppErrorBoundary } from './components/app-error-boundary';
import { AppToaster } from './components/app-toaster';
import { EncryptionKeyProvider } from './contexts/encryption-key-context';
import { PrivacyModeProvider } from './contexts/privacy-mode-context';
import { SyncProvider } from './contexts/sync-context';
import { initializeTheme } from './hooks/use-appearance';
import { initializeChartColorScheme } from './hooks/use-chart-color-scheme';
import { installChunkLoadRecovery } from './lib/chunk-load-recovery';
import { installDeferredPropsRecovery } from './lib/deferred-props-recovery';
import { installFailedNavigationToast } from './lib/failed-navigation-toast';
import { leavePage } from './lib/leave-page';
import { seedPageState } from './lib/page-state';
import { initializePostHog } from './lib/posthog';
import {
    isBrowserExtensionNoise,
    isChunkLoadErrorEvent,
    isFacebookInAppBrowserJavaBridgeNoise,
    isOutlookSafeLinksNoise,
    isPageLeaveAbortNoise,
    isPostMessageDataCloneNoise,
    isSafariCashbackExtensionNoise,
    isUnattendedRequestNoise,
} from './lib/sentry';
import { installSessionExpiryRecovery } from './lib/session-expiry-recovery';
import { trackUnattendedRequests } from './lib/unattended-requests';
import type { ExpiredBankingConnectionNotification, SharedData } from './types';
import { __ } from './utils/i18n';

installChunkLoadRecovery();
installDeferredPropsRecovery();
installSessionExpiryRecovery();
trackUnattendedRequests();
installFailedNavigationToast();

Sentry.init({
    dsn: import.meta.env.SENTRY_LARAVEL_DSN,
    environment: import.meta.env.MODE,
    integrations: [],
    tracesSampleRate: 0,
    sendDefaultPii: true,
    beforeSend(event) {
        if (
            isChunkLoadErrorEvent(event) ||
            isPageLeaveAbortNoise(event) ||
            isUnattendedRequestNoise(event) ||
            isBrowserExtensionNoise(event) ||
            isPostMessageDataCloneNoise(event) ||
            isFacebookInAppBrowserJavaBridgeNoise(event) ||
            isSafariCashbackExtensionNoise(event) ||
            isOutlookSafeLinksNoise(event)
        ) {
            return null;
        }

        return event;
    },
    enabled:
        import.meta.env.MODE === 'production' &&
        Boolean(import.meta.env.SENTRY_LARAVEL_DSN),
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
                    leavePage(firstConnection.reconnect_url);
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
            // Excluding the tests that live next to their pages: without it Vite
            // treats each one as a page entry and bundles what it imports, which
            // pulls vitest into the production build.
            import.meta.glob<{ default: ResolvedComponent }>([
                './pages/**/*.tsx',
                '!./pages/**/*.test.tsx',
            ]),
        ).then((module) => module.default),
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
        const initialChallenges = initialPageProps?.challenges ?? null;

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
                await axios.patch(updateTimezone.url(), {
                    timezone: detectedTimezone,
                });
            } catch {
                hasAttemptedTimezoneBackfill = false;
            }
        };

        // Translations and the currency scale, both read before the first
        // paint. Seeded from the server-rendered page data, then kept in sync on
        // every Inertia navigation.
        seedPageState(initialPageProps);

        router.on('navigate', (event) => {
            const pageProps = event.detail.page.props as unknown as SharedData;

            seedPageState(pageProps);
            void syncUserTimezone(pageProps);
        });

        void syncUserTimezone(initialPageProps);

        root.render(
            <StrictMode>
                <AppErrorBoundary>
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
                                <UncategorizedToast
                                    initialChallenges={initialChallenges}
                                />
                                <MedalUnlockedToast
                                    initialChallenges={initialChallenges}
                                    userId={initialUser?.id}
                                />
                                <AppToaster />
                            </SyncProvider>
                        </PrivacyModeProvider>
                    </EncryptionKeyProvider>
                </AppErrorBoundary>
            </StrictMode>,
        );
    },
    progress: {
        color: getProgressBarColor(),
    },
});
