import {
    addMediaQueryListener,
    removeMediaQueryListener,
} from '@/lib/media-query';
import { useCallback, useSyncExternalStore } from 'react';

/**
 * Whether a media query matches, kept live. The server has no viewport, so it
 * renders with `serverValue` and the client corrects it after hydration.
 */
export function useMediaQuery(query: string, serverValue = false): boolean {
    const subscribe = useCallback(
        (callback: () => void) => {
            const mql = window.matchMedia(query);

            addMediaQueryListener(mql, callback);
            return () => removeMediaQueryListener(mql, callback);
        },
        [query],
    );

    return useSyncExternalStore(
        subscribe,
        () => window.matchMedia(query).matches,
        () => serverValue,
    );
}
