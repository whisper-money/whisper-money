/**
 * Cross-browser MediaQueryList change subscription.
 *
 * Safari <14 and other legacy browsers do not implement
 * MediaQueryList.addEventListener / removeEventListener — the property is
 * undefined, so calling it throws "addEventListener is not a function". At app
 * boot (initializeTheme) that aborts the whole mount and white-screens the app
 * for those users. Such browsers only expose the deprecated addListener /
 * removeListener, so fall back to them when the modern API is missing.
 */
export function addMediaQueryListener(
    mql: MediaQueryList,
    handler: () => void,
): void {
    if (typeof mql.addEventListener === 'function') {
        mql.addEventListener('change', handler);

        return;
    }

    // Deprecated fallback for Safari <14 / legacy browsers. Guarded so a
    // hypothetical MediaQueryList exposing neither API no-ops instead of
    // re-introducing the crash this helper exists to prevent.
    if (typeof mql.addListener === 'function') {
        mql.addListener(handler);
    }
}

export function removeMediaQueryListener(
    mql: MediaQueryList,
    handler: () => void,
): void {
    if (typeof mql.removeEventListener === 'function') {
        mql.removeEventListener('change', handler);

        return;
    }

    if (typeof mql.removeListener === 'function') {
        mql.removeListener(handler);
    }
}
