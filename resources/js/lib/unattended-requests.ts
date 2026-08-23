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
 */
const unattendedAt = new Map<string, number>();

/**
 * Backstop for a request nobody ever asked for afterwards. Matched to Inertia's own
 * `cacheFor` default: past that a successful prefetch has been evicted, so a click
 * issues a fresh request and its failure is the user's, not ours to explain away.
 * Inertia sets no XHR timeout, so a hung request can still fail after this and be
 * reported - which is the safe direction to be wrong in.
 */
const ATTRIBUTION_WINDOW_MS = 30_000;

export function trackUnattendedRequests(): void {
    router.on('prefetching', (event) => {
        unattendedAt.set(requestKey(event.detail.visit.url), Date.now());
    });

    // The moment someone asks for the page themselves, its failure is theirs and not
    // a guess - including when they click while our prefetch is still in flight,
    // which is the common case: Inertia hands them that same request and shows the
    // progress bar, so they are visibly waiting on it. This also keeps the map
    // bounded, rather than relying on the window above.
    //
    // A poll is recognised here rather than on its own event because Inertia has
    // none for it. It marks the visit it creates for a poll with `poll: true`, on
    // the internal shape of the visit rather than the `PendingVisit` this event is
    // typed as - hence the cast. If a future version stops setting it, the polls
    // simply stop being recognised and their noise comes back, which is the safe
    // direction to be wrong in.
    router.on('before', (event) => {
        const { visit } = event.detail;

        if ((visit as { poll?: boolean }).poll === true) {
            unattendedAt.set(requestKey(visit.url), Date.now());

            return;
        }

        if (!visit.prefetch) {
            unattendedAt.delete(requestKey(visit.url));
        }
    });
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
