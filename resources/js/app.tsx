import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
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
import type { SharedData } from './types';

Sentry.init({
    dsn: 'https://47f7a823afae4c2f93ab3159ca7c0a3a@bugsink.whisper.money/2',
    environment: import.meta.env.MODE,
    integrations: [],
    tracesSampleRate: 0,
    enabled: import.meta.env.PROD,
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

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

        root.render(
            <StrictMode>
                <EncryptionKeyProvider>
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
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
