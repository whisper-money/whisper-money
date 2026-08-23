import { router } from '@inertiajs/react';

/**
 * Asks again for the deferred props that died on the network.
 *
 * `<Deferred>` renders its fallback until the props are defined, and it has no
 * error path: Inertia's `rescue` covers props the *server* chose to rescue, not a
 * request that never arrived. So when the transport fails, the skeleton stays up
 * for as long as the page lives - and the dashboard, the first page after logging
 * in, is three of them. Nothing on screen says why, which reads as an app that is
 * simply broken. It is the same failure the connections page had before #822, one
 * layer down.
 *
 * One retry, a couple of seconds later, once per page. A blip is the common case
 * and asking again is the whole fix; a connection that is really gone is already
 * being reported by the failed-navigation toast, and retrying past that would
 * trade one silence for a loop.
 */
const RETRY_DELAY_MS = 2_000;

let pendingProps: string[] | null = null;
let retriedThisPage = false;

export function installDeferredPropsRecovery(): void {
    router.on('before', (event) => {
        const { visit } = event.detail;

        // Inertia marks the request it makes for deferred props on the internal
        // shape of the visit, which the `before` event is not typed as - hence the
        // cast, the same one the unattended-request tracker needs for a poll. If a
        // future version stops setting it, nothing is retried and the skeleton
        // behaves as it does today.
        if ((visit as { deferredProps?: boolean }).deferredProps === true) {
            pendingProps = visit.only;
        }
    });

    router.on('navigate', () => {
        pendingProps = null;
        retriedThisPage = false;
    });

    router.on('networkError', () => {
        if (pendingProps === null || retriedThisPage) {
            return;
        }

        // Deliberately not matched against the failed request's url. The window
        // this state is open for is one in-flight deferred load, and the worst a
        // mismatch can do is ask for props we already have.
        const only = pendingProps;

        pendingProps = null;
        retriedThisPage = true;

        window.setTimeout(() => router.reload({ only }), RETRY_DELAY_MS);
    });
}
