import {
    readStoredValue,
    removeStoredValue,
    writeStoredValue,
} from '@/lib/safe-storage';

/**
 * Safe mode opens a dashboard without any user widget, so a widget that hangs
 * the tab (an infinite loop the validator cannot see) can still be removed.
 *
 * - `?safe=1` asks for it.
 * - It also turns on by itself when the last load of user widgets never
 *   finished: a marker is written before they mount and cleared a moment
 *   after all of them have drawn. A tab that froze never clears it, so the
 *   next load finds it. localStorage rather than sessionStorage, because a
 *   frozen tab is usually closed, not reloaded.
 *
 * Storage may be unavailable (private mode, blocked site data): every access
 * goes through safe-storage, and without it there is no automatic safe mode,
 * only `?safe=1`.
 */
export const SAFE_MODE_PARAM = 'safe';

export const LOADING_MARKER_KEY = 'wm:user-widgets-loading';

export type SafeModeReason = 'requested' | 'unfinished';

export function safeModeReason(search: string): SafeModeReason | null {
    if (new URLSearchParams(search).get(SAFE_MODE_PARAM) === '1') {
        return 'requested';
    }

    return readStoredValue(LOADING_MARKER_KEY) !== null ? 'unfinished' : null;
}

export function markUserWidgetsLoading(): void {
    writeStoredValue(LOADING_MARKER_KEY, new Date().toISOString());
}

export function markUserWidgetsLoaded(): void {
    removeStoredValue(LOADING_MARKER_KEY);
}

/** The current URL without `?safe=1`, to leave safe mode. */
export function withoutSafeModeParam(href: string): string {
    const url = new URL(href);

    url.searchParams.delete(SAFE_MODE_PARAM);

    return url.pathname + url.search + url.hash;
}
