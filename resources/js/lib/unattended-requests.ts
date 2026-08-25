import { router } from '@inertiajs/react';

/**
 * Remembers which URLs nobody asked for, so a failed one can be told apart from a
 * request someone was actually waiting for.
 *
 * Two kinds qualify. A hover prefetch, and a background poll: the onboarding
 * create-account step reloads every 4s and the connections page every 5s, both to
 * notice work that finished somewhere else. A user sitting on either page has not
 * asked for anything, so a connection that blips for one beat owes them neither a
 * toast nor a Sentry issue - the next tick, four seconds later, is the answer.
 *
 * Every sidebar item is a `<Link prefetch>`, so hovering the nav fetches a page
 * nobody has committed to visiting. When one of those fails at the transport level
 * the rejection is unreachable from app code: it is discarded inside Inertia, and
 * `preventDefault()` on the networkError event would skip `onPrefetchError` — which
 * is the call that removes the dead entry from `inFlightRequests`. Without it a
 * later real click resolves the cache to that entry and awaits a promise that can
 * never settle, so the click hangs with the progress bar up. Recognising it in
 * Sentry's beforeSend is what is left, and that needs to know it was a guess.
 *
 * Kept by timestamp rather than cleared on completion, because `finish` fires on the
 * rejecting chain before the unhandled rejection is captured — anything cleared
 * there would already be gone by the time beforeSend asks.
 *
 * The timestamp is when the request *ended*, or {@link STILL_IN_FLIGHT} while it is
 * running.
 */
const unattendedAt = new Map<string, number>();

/**
 * The stamp on a request that has not come back yet, which no window can outlast.
 *
 * A request nobody asked for stays a request nobody asked for however long it takes
 * to die, and dying is exactly what takes long: Inertia sets no XHR timeout, so a
 * connection that drops mid-flight leaves the browser sitting on the socket for
 * minutes before it gives up. Ageing an entry out from the moment the request
 * *started* meant the hung ones - the only ones slow enough to matter - were the ones
 * that lapsed, and a 4s poll came back as a navigation someone was waiting for.
 */
const STILL_IN_FLIGHT = Infinity;

/**
 * URLs a real request is in flight for right now, which a poll to the same URL
 * must not overwrite.
 */
const awaitedUrls = new Set<string>();

/**
 * Backstop for a request nobody ever asked for afterwards. Matched to Inertia's own
 * `cacheFor` default: past that a successful prefetch has been evicted, so a click
 * issues a fresh request and its failure is the user's, not ours to explain away.
 *
 * Measured from the moment the request *ended*, not the moment it started - see the
 * `finish` listener below.
 */
const ATTRIBUTION_WINDOW_MS = 30_000;

export function trackUnattendedRequests(): void {
    router.on('prefetching', (event) => {
        unattendedAt.set(requestKey(event.detail.visit.url), STILL_IN_FLIGHT);
    });

    // The moment someone asks for the page themselves, its failure is theirs and not
    // a guess - including when they click while our prefetch is still in flight,
    // which is the common case: Inertia hands them that same request and shows the
    // progress bar, so they are visibly waiting on it. This also keeps the map
    // bounded, rather than relying on the window above.
    //
    // A poll is recognised here rather than on its own event because Inertia has
    // none for it.
    router.on('before', (event) => {
        const { visit } = event.detail;
        const key = requestKey(visit.url);

        if (isPoll(visit)) {
            // Never claim a URL somebody is already waiting on. Inertia does not
            // cancel an in-flight visit to the page you are already on, and a poll
            // reloads exactly that page - so a tick can land between a real reload
            // and its failure, and re-arm the very entry that decides whether that
            // failure is worth telling anyone about.
            if (!awaitedUrls.has(key)) {
                unattendedAt.set(key, STILL_IN_FLIGHT);
            }

            return;
        }

        if (!visit.prefetch) {
            unattendedAt.delete(key);
            awaitedUrls.add(key);
        }
    });

    // However it ended. The entry above only has to outlive the request it belongs
    // to, and `finish` is the one event that fires on every outcome.
    router.on('finish', (event) => {
        const { visit } = event.detail;
        const key = requestKey(visit.url);

        if (isPoll(visit) || visit.prefetch) {
            // Start the window here, now that there is an end to measure from. Until
            // this point the entry was {@link STILL_IN_FLIGHT} and no elapsed time
            // could age it out.
            //
            // Only ever stamp an entry that is still ours: a real visit to the same
            // URL deletes it, and that deletion has to stick.
            if (unattendedAt.has(key)) {
                unattendedAt.set(key, Date.now());
            }

            return;
        }

        awaitedUrls.delete(key);
    });
}

/**
 * Inertia marks the visit its own poller creates with `poll: true`, on the internal
 * shape of the visit rather than the `PendingVisit` these events are typed as -
 * hence the cast. If a future version stops setting it, polls simply stop being
 * recognised and their noise comes back, which is the safe direction to be wrong in.
 */
function isPoll(visit: { poll?: boolean }): boolean {
    return visit.poll === true;
}

/**
 * Inertia strips the fragment before sending, so the URL in the error never carries
 * one and the key must not either.
 */
function requestKey(url: URL): string {
    const withoutHash = new URL(url.href);
    withoutHash.hash = '';

    return withoutHash.href;
}

/**
 * Two callers ask this about the same failure, and they do not get to disagree.
 *
 * Inertia's `networkError` event is a synchronous `dispatchEvent` inside the
 * rejecting `.catch()`, so the toast in {@link installFailedNavigationToast} asks
 * first - before the `.finally()` that fires `finish`. Sentry's beforeSend asks last,
 * off the unhandled rejection. An entry has to read the same way at both moments, and
 * the expiry below deletes as it reports, so a disagreement is not merely two
 * different answers: the first caller destroys the entry the second one needed.
 *
 * {@link STILL_IN_FLIGHT} is what keeps them in step. The request is unfinished when
 * the toast asks and freshly stamped when beforeSend does, and neither reading can
 * age out.
 */
export function wasUnattended(url: string): boolean {
    const at = unattendedAt.get(url);

    if (at === undefined) {
        return false;
    }

    if (Date.now() - at > ATTRIBUTION_WINDOW_MS) {
        unattendedAt.delete(url);

        return false;
    }

    return true;
}
