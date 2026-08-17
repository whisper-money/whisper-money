import { router } from '@inertiajs/react';

/**
 * Remembers which URLs we speculatively fetched, so a failed one can be told apart
 * from a request the user actually asked for.
 *
 * Every sidebar item is a `<Link prefetch>`, so hovering the nav fires a request for
 * a page nobody has committed to visiting. When one of those fails at the transport
 * level Inertia rejects inside its own prefetch promise — a rejection app code cannot
 * reach, since `preventDefault()` on the networkError event would also skip the
 * `onPrefetchError` cleanup that keeps the URL prefetchable. So the only place left
 * to recognise it is Sentry's beforeSend, and that needs to know it was a guess.
 *
 * Kept by timestamp rather than cleared on completion: `finish` fires before the
 * rejection is captured, so anything cleared there would already be gone by the time
 * beforeSend asks.
 */
const prefetchedAt = new Map<string, number>();

/**
 * How long a prefetch stays attributable. Longer than any request that is still in
 * flight, short enough that a genuine failure minutes later is still reported.
 */
const ATTRIBUTION_WINDOW_MS = 60_000;

export function trackPrefetchedUrls(): () => void {
    return router.on('prefetching', (event) => {
        prefetchedAt.set(event.detail.visit.url.href, Date.now());
    });
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
