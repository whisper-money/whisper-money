import {
    readOwnedValue,
    removeStoredValue,
    writeOwnedValue,
} from '@/lib/safe-storage';

/**
 * Safe mode opens a dashboard without any user widget, so a widget that hangs
 * the tab (an infinite loop the validator cannot see) can still be removed.
 *
 * - `?safe=1` asks for it.
 * - It also turns on by itself when the last load of user widgets never
 *   finished: a marker is written before they mount and cleared a moment
 *   after all of them have drawn, or when the page goes away while it still
 *   responds (`pagehide`). A tab that froze runs neither, so the next load
 *   finds the marker. localStorage rather than sessionStorage, because a
 *   frozen tab is usually closed, not reloaded.
 * - The marker belongs to the user (another account on the same browser
 *   ignores it) and names the tab that wrote it, which is the only one that
 *   clears it. Known limit: a second tab opened while the first is still
 *   drawing its widgets reads that tab's marker and opens in safe mode.
 * - A hang that starts after the grace period (on hover, say) is not caught:
 *   `?safe=1` is the way out then.
 *
 * Storage may be unavailable (private mode, blocked site data): every access
 * goes through safe-storage, and without it there is no automatic safe mode,
 * only `?safe=1`.
 */
export const SAFE_MODE_PARAM = 'safe';

export const LOADING_MARKER_KEY = 'wm:user-widgets-loading';

export type SafeModeReason = 'requested' | 'unfinished';

/** This page load, so a tab only ever clears the marker it wrote. */
const TAB_ID = Math.random().toString(36).slice(2);

export function safeModeReason(
    search: string,
    userId: string,
): SafeModeReason | null {
    if (new URLSearchParams(search).get(SAFE_MODE_PARAM) === '1') {
        return 'requested';
    }

    return readOwnedValue(LOADING_MARKER_KEY, userId) !== null
        ? 'unfinished'
        : null;
}

export function markUserWidgetsLoading(userId: string): void {
    writeOwnedValue(LOADING_MARKER_KEY, userId, TAB_ID);
}

/** Clears the marker this tab wrote; another tab's stays. */
export function markUserWidgetsLoaded(userId: string): void {
    if (readOwnedValue(LOADING_MARKER_KEY, userId) === TAB_ID) {
        removeStoredValue(LOADING_MARKER_KEY);
    }
}

/** Leaving safe mode: whatever marker this user left behind goes. */
export function clearUserWidgetsMarker(userId: string): void {
    if (readOwnedValue(LOADING_MARKER_KEY, userId) !== null) {
        removeStoredValue(LOADING_MARKER_KEY);
    }
}

/** The current URL without `?safe=1`, to leave safe mode. */
export function withoutSafeModeParam(href: string): string {
    const url = new URL(href);

    url.searchParams.delete(SAFE_MODE_PARAM);

    return url.pathname + url.search + url.hash;
}
