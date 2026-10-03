import { detectPlatform, type Platform } from '@/lib/key-combo';
import { useSyncExternalStore } from 'react';

function subscribe(): () => void {
    // The platform never changes while the page is open.
    return () => {};
}

function getServerSnapshot(): null {
    return null;
}

/**
 * Mac or not, for what the UI says about keys (⌘ or Ctrl). Null on the server
 * and through hydration, which cannot know either: render nothing platform
 * specific until it is known, and React swaps it in right after hydrating
 * without a mismatch.
 */
export function usePlatform(): Platform | null {
    return useSyncExternalStore(subscribe, detectPlatform, getServerSnapshot);
}
