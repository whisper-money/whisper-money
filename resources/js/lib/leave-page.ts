/**
 * Navigates out of the SPA with a full page load.
 *
 * Assigning `window.location` aborts every request still in flight, and browsers
 * report that abort to XHR as a transport failure rather than a cancellation
 * (WebKit especially). So an onboarding poll or a hover prefetch that happened to
 * be running when the user left surfaces as an unhandled `AxiosError: Network
 * Error` — a bug report for a request that never actually failed.
 *
 * Recording the departure lets Sentry tell "the request died with the page" apart
 * from "the user's connection dropped". Always prefer Inertia's `router`/`Link`
 * for internal navigation; this is only for leaving the app entirely (a bank's
 * authorization page, the Stripe billing portal, a locale reload).
 */

// ponytail: one-way flag, no reset — it dies with the document. If the browser
// cancels the navigation the page survives with the flag set and we under-report
// network errors for the rest of that session. Add a time window only if that
// silence ever hides something real; a window would be worse at the common case,
// where a slow bank redirect leaves the old page polling for many seconds.
let leaving = false;

export function leavePage(url: string): void {
    leaving = true;
    window.location.href = url;
}

export function isLeavingPage(): boolean {
    return leaving;
}
