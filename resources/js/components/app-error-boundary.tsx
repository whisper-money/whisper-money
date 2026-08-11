import { Button } from '@/components/ui/button';
import { reloadOnChunkLoadError } from '@/lib/chunk-load-recovery';
import { leavePage, reloadPage } from '@/lib/leave-page';
import { getStorage } from '@/lib/safe-storage';
import { dashboard } from '@/routes';
import { __ } from '@/utils/i18n';
import * as Sentry from '@sentry/react';
import { useEffect, type ReactNode } from 'react';

/**
 * React unmounts the entire tree when a render or effect throws, so without a
 * boundary one bad prop anywhere leaves the user staring at a blank page with
 * no way out and no hint that anything happened.
 *
 * The boundary also becomes the only thing that reports the crash: once React
 * handles the error it never reaches window.onerror, which is what Sentry's
 * global handler listens to.
 */
export function AppErrorBoundary({ children }: { children: ReactNode }) {
    return (
        <Sentry.ErrorBoundary
            fallback={<AppErrorFallback />}
            // Supplying a fallback makes the SDK mark the crash handled, which
            // would drop these out of crash-free sessions and out of any
            // `error.handled:false` alert. Showing the user a way out is not
            // the same as the app having coped with it.
            handled={false}
            onError={(error) => {
                // A chunk left behind by a deploy is self-healing, and the
                // global listeners only see errors React did not catch, so the
                // recovery has to be repeated here.
                reloadOnChunkLoadError(error, {
                    storage: getStorage('session'),
                });
            }}
        >
            {children}
        </Sentry.ErrorBoundary>
    );
}

/**
 * Reloading beats resetError() here: the boundary sits above the Inertia page,
 * so re-rendering would just replay the same props that threw.
 *
 * Every way out is a full page load. Inertia's popstate listener lives at
 * module scope and outlives React unmounting the page, so once the fallback is
 * up a client-side navigation changes the URL and paints nothing - the screen
 * looks frozen. Turning those back into document loads is what unsticks it.
 */
function AppErrorFallback() {
    useEffect(() => {
        const reload = reloadPage;

        window.addEventListener('popstate', reload);

        return () => window.removeEventListener('popstate', reload);
    }, []);

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-4 bg-background p-6 text-center">
            <h1 className="text-lg font-medium text-foreground">
                {__('Something went wrong.')}
            </h1>

            <p className="max-w-sm text-sm text-muted-foreground">
                {__(
                    'We could not display this page. Reloading usually fixes it.',
                )}
            </p>

            <div className="flex flex-wrap items-center justify-center gap-2">
                <Button onClick={reloadPage}>{__('Try again')}</Button>

                <Button
                    variant="outline"
                    onClick={() => {
                        leavePage(dashboard().url);
                    }}
                >
                    {__('Go to Dashboard')}
                </Button>
            </div>
        </div>
    );
}
