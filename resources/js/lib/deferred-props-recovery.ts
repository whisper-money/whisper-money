import { router } from '@inertiajs/react';

/**
 * Asks again for the deferred props that died on the network.
 *
 * `<Deferred>` renders its fallback until the props are defined and has no error
 * path: Inertia's `rescue` covers props the *server* chose to rescue, not a
 * request that never arrived. So when the transport fails, the skeleton stays up
 * for as long as the page lives - and the dashboard, the first page after logging
 * in, is three of them. Nothing on screen says why, which reads as an app that is
 * simply broken.
 *
 * The event order this hangs off, all of it verified in `@inertiajs/core` rather
 * than assumed, because the first version of this file got it wrong and did
 * nothing at all:
 *
 * - `before` for the deferred request and `navigate` for the page it belongs to
 *   fire in the same tick (`Page.set` calls `loadDeferredProps` and then
 *   `fireNavigateEvent`), so `navigate` cannot be used to bound this - it arrives
 *   long before the request it would be bounding has a chance to fail.
 * - `networkError` fires from the request's `.catch`, `finish` from the `.finally`
 *   after it, so a failure is always seen while the request is still pending here.
 * - The retry is a plain `router.reload`, which Inertia does not mark as a
 *   deferred-props request. So a retry that fails arms nothing, and one is the
 *   most this can ever do - no counter needed to say so.
 *
 * Assumes one deferred group in flight at a time, which is what the two pages
 * using `<Deferred>` do today - the dashboard defers all three of its props under
 * a single group, so `loadDeferredProps` makes one request. A second group would
 * share this slot and only the later one would be retried, leaving the earlier
 * skeleton exactly as stuck as it is today. Key this by group if that ever
 * happens.
 *
 * What it does not do is give the user a way out once the retry has failed too.
 * The skeleton is stuck again, and all they have is the failed-navigation toast
 * telling them the server could not be reached. That is the ceiling on purpose:
 * the blip is the common case and this fixes it, while a real error state inside
 * `<Deferred>`'s fallback is a per-page change on top of a framework component
 * that has no error path at all.
 */
const RETRY_DELAY_MS = 2_000;

let pendingProps: string[] | null = null;
let retryTimer: number | null = null;

function isDeferredPropsVisit(visit: object): boolean {
    // Inertia marks it on the internal shape of the visit, which these events are
    // not typed as. If a future version stops setting it, nothing is retried and
    // the skeleton behaves as it does today.
    return (visit as { deferredProps?: boolean }).deferredProps === true;
}

export function installDeferredPropsRecovery(): void {
    router.on('before', (event) => {
        const { visit } = event.detail;

        if (isDeferredPropsVisit(visit)) {
            pendingProps = visit.only;
        }
    });

    // However it ended. A success needs no retry, and a failure has already
    // scheduled one below - either way the request is no longer pending, and
    // leaving it armed would let an unrelated failure later on the same page pull
    // the dashboard's heaviest queries for no reason.
    router.on('finish', (event) => {
        if (isDeferredPropsVisit(event.detail.visit)) {
            pendingProps = null;
        }
    });

    router.on('networkError', () => {
        if (pendingProps === null) {
            return;
        }

        const only = pendingProps;

        pendingProps = null;
        retryTimer = window.setTimeout(() => {
            retryTimer = null;
            router.reload({ only });
        }, RETRY_DELAY_MS);
    });

    // A page the user has left must not be asked for its props: `router.reload`
    // targets wherever they are now, and `only` would name props that page does
    // not have.
    router.on('navigate', () => {
        if (retryTimer !== null) {
            window.clearTimeout(retryTimer);
            retryTimer = null;
        }
    });
}
