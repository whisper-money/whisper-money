import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** What `en` formatted like before regions existed, and the only safe last resort. */
const FALLBACK_LOCALE = 'en-US';

/**
 * The locale this reader's amounts and dates are written in — a full region
 * like `es-MX`, not the two-letter language the app is translated into.
 *
 * The prop carries the region while the server's own `app()->getLocale()` stays
 * two letters, and that divergence is deliberate: see
 * `HandleInertiaRequests::formatLocaleFor()`. Translations are unaffected either
 * way, because `i18n.ts` reads the `translations` prop and never this one.
 *
 * The value comes off the user's row, so it is never module state: two SSR
 * renders sharing one process must not be able to read each other's region. See
 * the warning on `setCurrencyDecimals()` in `utils/currency.ts`.
 */
export function useLocale(): string {
    const locale = usePage<SharedData>().props.locale;

    try {
        // Every Intl constructor throws RangeError on a malformed tag, which
        // costs the whole screen rather than one mis-written number. The server
        // only ever sends a value off a closed list, so this catches a prop
        // that has gone stale or been tampered with, nothing else.
        Intl.getCanonicalLocales(locale);
    } catch {
        return FALLBACK_LOCALE;
    }

    return locale;
}
