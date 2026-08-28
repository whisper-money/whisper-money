/**
 * Web storage access that cannot take the app down with it.
 *
 * The globals are not always usable. Some Android WebViews expose them as
 * `null` when the host app disables DOM storage, and browsers configured to
 * block cookies/site data throw `SecurityError` on the very first access. At
 * app boot — initializeTheme, initializeChartColorScheme, the landing page's
 * locale effect — either one aborts the mount and white-screens the app
 * (PHP-LARAVEL-57, PHP-LARAVEL-4Y).
 *
 * Note the try/catch is doing the real work and is not redundant with the
 * optional chaining: the SecurityError is thrown by the property *read*
 * itself, before `?.` ever gets to look at the value. Do not "simplify" it
 * away. Same spirit as ./media-query.ts, which exists for the same class of
 * boot-time crash.
 *
 * Reads fall back to null and writes quietly no-op, which is right for the
 * display preferences using this. Do not route anything whose loss is a data
 * problem rather than a cosmetic one (an encryption key, say) through it
 * without telling the user it did not persist.
 */
export function getStorage(area: 'local' | 'session'): Storage | undefined {
    try {
        return (
            (area === 'local' ? window.localStorage : window.sessionStorage) ??
            undefined
        );
    } catch {
        return undefined;
    }
}

export function readStoredValue(key: string): string | null {
    try {
        return getStorage('local')?.getItem(key) ?? null;
    } catch {
        return null;
    }
}

export function writeStoredValue(key: string, value: string): void {
    try {
        getStorage('local')?.setItem(key, value);
    } catch {
        // Storage disabled, private mode, or quota exhausted: the preference
        // simply does not survive the session.
    }
}

export function removeStoredValue(key: string): void {
    try {
        getStorage('local')?.removeItem(key);
    } catch {
        // Same as above: nothing was persisted, so nothing needs clearing.
    }
}
