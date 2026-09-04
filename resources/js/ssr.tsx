import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import ReactDOMServer from 'react-dom/server';
import { EncryptionKeyProvider } from './contexts/encryption-key-context';
import { PrivacyModeProvider } from './contexts/privacy-mode-context';
import { SyncProvider } from './contexts/sync-context';
import { seedPageState } from './lib/page-state';
import type { SharedData } from './types';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) =>
            resolvePageComponent(
                `./pages/${name}.tsx`,
                // See app.tsx: keeps the page tests out of the bundle.
                import.meta.glob<{ default: ResolvedComponent }>([
                    './pages/**/*.tsx',
                    '!./pages/**/*.test.tsx',
                ]),
            ).then((module) => module.default),
        setup: ({ App, props }) => {
            const initialPageProps = props.initialPage?.props as
                | Partial<SharedData>
                | undefined;
            const initialUser = initialPageProps?.auth?.user ?? null;
            const initialIsAuthenticated = Boolean(initialUser);
            const hasEncryptionSetup =
                (initialPageProps?.hasEncryptionSetup as boolean) ?? false;

            // Without this the server-rendered pass falls back to the CLDR
            // scale and to the untranslated keys, both of which disagree with
            // the client and would swap the text on hydration.
            seedPageState(initialPageProps);

            return (
                <EncryptionKeyProvider hasEncryptionSetup={hasEncryptionSetup}>
                    <PrivacyModeProvider>
                        <SyncProvider
                            initialIsAuthenticated={initialIsAuthenticated}
                            initialUser={initialUser}
                        >
                            <App {...props} />
                        </SyncProvider>
                    </PrivacyModeProvider>
                </EncryptionKeyProvider>
            );
        },
    }),
);
