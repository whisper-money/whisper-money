import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import * as Sentry from '@sentry/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import {
    CircleCheckIcon,
    InfoIcon,
    Loader2Icon,
    OctagonXIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';
import { EncryptionKeyProvider } from './contexts/encryption-key-context';
import { PrivacyModeProvider } from './contexts/privacy-mode-context';
import { SyncProvider } from './contexts/sync-context';
import { initializeTheme } from './hooks/use-appearance';
import { initializePostHog } from './lib/posthog';
import type { SharedData } from './types';
import { setTranslations } from './utils/i18n';

Sentry.init({
    dsn: 'https://47f7a823afae4c2f93ab3159ca7c0a3a@bugsink.whisper.money/2',
    environment: import.meta.env.MODE,
    integrations: [],
    tracesSampleRate: 0,
    enabled: import.meta.env.PROD,
});

initializePostHog();

// Initialize theme before creating the app so progress bar color is correct
initializeTheme();

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

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

        // Initialize translations from server-rendered page data
        setTranslations(
            (initialPageProps?.translations as Record<string, string>) ?? {},
        );

        // Keep translations in sync on every Inertia navigation
        router.on('navigate', (event) => {
            const pageProps = event.detail.page.props as SharedData;
            setTranslations(
                (pageProps?.translations as Record<string, string>) ?? {},
            );
        });

        root.render(
            <StrictMode>
                <EncryptionKeyProvider hasEncryptionSetup={hasEncryptionSetup}>
                    <PrivacyModeProvider>
                        <SyncProvider
                            initialIsAuthenticated={initialIsAuthenticated}
                            initialUser={initialUser}
                        >
                            <App {...props} />
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
