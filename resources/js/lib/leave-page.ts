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
let leaving = false;

export function leavePage(url: string): void {
    leaving = true;
    window.location.href = url;
}

export function reloadPage(): void {
    leaving = true;
    window.location.reload();
}

export function isLeavingPage(): boolean {
    return leaving;
}

// SSR renders this module — pages/welcome.tsx and pages/settings/connections.tsx
// import it, and ssr.tsx globs every page — so nothing here may touch `window` at
// import time.
if (typeof window !== 'undefined') {
    // Not every departure comes through the functions above: an external <a>, the
    // back button and closing the tab unload the document too. Those don't get the
    // eager flag, which is the whole reason the functions exist — by the time
    // `pagehide` fires the abort may already have been reported.
    window.addEventListener('pagehide', () => {
        leaving = true;
    });

    // The document does not always die once we leave. Back/forward cache restores
    // it with the flag still set, and an iOS PWA hands the bank redirect to Safari
    // and stays alive polling (see pages/onboarding/index.tsx) — either way the
    // user carries on in a live page whose network errors we'd have silenced for
    // the rest of the session.
    //
    // Becoming visible again is the proof that we stayed. It cannot clear a
    // departure that is genuinely in progress, because a same-window redirect
    // never hides the page; and if it ever fires early we over-report, which is
    // the safe direction.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            leaving = false;
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            leaving = false;
        }
    });
}
