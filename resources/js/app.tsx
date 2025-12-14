import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
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
                            <div className="[&_[data-sonner-toaster]]:!top-4 [&_[data-sonner-toaster]]:!left-1/2 [&_[data-sonner-toaster]]:!-translate-x-1/2 [&_[data-sonner-toaster]]:md:!top-auto [&_[data-sonner-toaster]]:md:!right-4 [&_[data-sonner-toaster]]:md:!bottom-4 [&_[data-sonner-toaster]]:md:!left-auto [&_[data-sonner-toaster]]:md:!translate-x-0">
                                <Toaster
                                    richColors
                                    icons={{
                                        success: (
                                            <CircleCheckIcon className="size-4" />
                                        ),
                                        info: <InfoIcon className="size-4" />,
                                        warning: (
                                            <TriangleAlertIcon className="size-4" />
                                        ),
                                        error: (
                                            <OctagonXIcon className="size-4" />
                                        ),
                                        loading: (
                                            <Loader2Icon className="size-4 animate-spin" />
                                        ),
                                    }}
                                />
                            </div>
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
