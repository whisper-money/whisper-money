import {
    addMediaQueryListener,
    removeMediaQueryListener,
} from '@/lib/media-query';
import { useSyncExternalStore } from 'react';

const MOBILE_BREAKPOINT = 768;

function getMediaQueryList(): MediaQueryList | null {
    if (typeof window === 'undefined') return null;
    return window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);
}

function subscribe(callback: () => void) {
    const mql = getMediaQueryList();
    if (!mql) return () => {};

    addMediaQueryListener(mql, callback);
    return () => removeMediaQueryListener(mql, callback);
}

function getSnapshot() {
    const mql = getMediaQueryList();
    return mql ? mql.matches : false;
}

function getServerSnapshot() {
    return false;
}

export function useIsMobile() {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
