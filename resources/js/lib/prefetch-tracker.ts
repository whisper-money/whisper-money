import { router } from '@inertiajs/react';

/**
 * Remembers which URLs we only speculatively fetched, so a failed one can be told
 * apart from a request someone was actually waiting for.
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
const prefetchedAt = new Map<string, number>();

/**
 * Backstop for a prefetch nobody ever asked for afterwards. Matched to Inertia's own
 * `cacheFor` default: past that a successful prefetch has been evicted, so a click
 * issues a fresh request and its failure is the user's, not ours to explain away.
 * Inertia sets no XHR timeout, so a hung request can still fail after this and be
 * reported - which is the safe direction to be wrong in.
 */
const ATTRIBUTION_WINDOW_MS = 30_000;

export function trackPrefetchedUrls(): void {
    router.on('prefetching', (event) => {
        prefetchedAt.set(requestKey(event.detail.visit.url), Date.now());
    });

    // The moment someone asks for the page themselves, its failure is theirs and not
    // a guess - including when they click while our prefetch is still in flight,
    // which is the common case: Inertia hands them that same request and shows the
    // progress bar, so they are visibly waiting on it. This also keeps the map
    // bounded, rather than relying on the window above.
    router.on('before', (event) => {
        if (!event.detail.visit.prefetch) {
            prefetchedAt.delete(requestKey(event.detail.visit.url));
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

export function wasPrefetched(url: string): boolean {
    const at = prefetchedAt.get(url);

    if (at === undefined) {
        return false;
    }

    if (Date.now() - at > ATTRIBUTION_WINDOW_MS) {
        prefetchedAt.delete(url);

        return false;
    }

    return true;
}
