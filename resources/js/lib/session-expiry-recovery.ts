import { router } from '@inertiajs/react';
import axios from 'axios';
import { reloadPage } from './leave-page';

/**
 * Reloads the page when the session behind it has already died.
 *
 * `SESSION_LIFETIME` is two hours, and nothing in the app notices when it
 * passes. A tab left open longer than that keeps rendering whatever the last
 * successful `GET` put into React state, so the screen stays perfectly intact
 * while every write from it comes back `419 CSRF token mismatch` — the
 * `XSRF-TOKEN` cookie expires with the session, so the token the request sends
 * is one the server no longer knows.
 *
 * That is what the onboarding AI-suggestions report turned out to be: a run that
 * completed fine, a review screen that looked fine 3.5 hours later, and a
 * "create the rules" button whose `POST` never got past the CSRF check. Laravel
 * does not report `HttpException`, so Sentry had nothing either — thirty days of
 * silence for a bug the user was staring at.
 *
 * Reloading is the whole fix: Laravel answers the fresh load by redirecting to
 * wherever it sends guests, so the user logs back in and lands somewhere real
 * instead of poking a dead screen. Hence no hardcoded `/login` here — that would
 * skip `AuthEntryPointService::guestRedirectRoute`, which is what decides where
 * a given guest actually belongs.
 *
 * Which of the two statuses you actually get depends on the Laravel version, so
 * both are handled. `PreventRequestForgery` (Laravel 13, replacing
 * `ValidateCsrfToken`) accepts any request carrying `Sec-Fetch-Site:
 * same-origin` without looking at the token at all — so a real browser tab now
 * gets past CSRF with a dead token and is turned away one middleware later by
 * `Authenticate`, as a `401` on anything asking for JSON. Verified in a browser
 * against a session deleted underneath it: `401`, not `419`. The `419` is still
 * what a request without that header gets, and what production reported.
 *
 * All three transports are covered, because all three break the same way and
 * none of them can tell the user why:
 *
 * - **axios**, which had no interceptor at all, so every `catch` in the app saw
 *   a 419 as an anonymous failure.
 * - **`fetch`**, wrapped once here rather than at each of the dozen call sites
 *   that build their own `X-XSRF-TOKEN` header. Only same-origin responses are
 *   looked at: `partials/header.tsx` calls the GitHub API, which answers 401 for
 *   its own reasons, and a bank's authorization endpoints are not ours to judge.
 *   The response is passed through untouched — this only reads `status`.
 * - **Inertia**, whose `httpException` event (v2's `invalid`) would otherwise pop
 *   the raw error-page modal over the app.
 *
 * The once-guard matters because a page usually has several requests in flight,
 * and five expiring together must not mean five reloads. It lives in memory, so
 * it resets on the reload it caused — which is fine, and cannot loop: the login
 * page Laravel then serves fires no authenticated request to expire.
 */
const SESSION_EXPIRED_STATUSES = [
    // The CSRF token died with the session, and the token is what was checked.
    419,
    // No session left, on a request that asked for JSON — Laravel cannot answer
    // an XHR with a redirect, so it says so with a status instead. What a stale
    // browser tab gets once the origin check has waved the missing token
    // through.
    401,
];

let recovering = false;

interface SessionExpiryRecoveryOptions {
    reload?: () => void;
}

/**
 * Whether a recovery reload is already on its way.
 *
 * For the `catch` blocks that have their own error message to show. An axios
 * response interceptor runs before the promise reaches the caller, so this is
 * already true by the time they ask - and "try again in a moment" is the wrong
 * thing to tell someone whose page is being replaced by a login screen.
 */
export function isRecoveringFromExpiredSession(): boolean {
    return recovering;
}

/**
 * Reloads if this status says the session is gone.
 *
 * Reports whether the status *was* an expired session rather than whether it
 * reloaded, so a caller can suppress what it would otherwise have shown even
 * when it is the second of several requests to expire at once. Only the reload
 * itself is once-per-page.
 */
function recoverFromExpiredSession(
    status: number | undefined,
    options: SessionExpiryRecoveryOptions,
): boolean {
    if (status === undefined || !SESSION_EXPIRED_STATUSES.includes(status)) {
        return false;
    }

    if (!recovering) {
        recovering = true;
        (options.reload ?? reloadPage)();
    }

    return true;
}

export function installSessionExpiryRecovery(
    options: SessionExpiryRecoveryOptions = {},
): void {
    // Rejecting anyway: the caller's `catch` still runs its cleanup while the
    // reload is getting under way.
    axios.interceptors.response.use(undefined, (error: unknown) => {
        if (axios.isAxiosError(error)) {
            recoverFromExpiredSession(error.response?.status, options);
        }

        return Promise.reject(error);
    });

    const originalFetch = window.fetch;

    window.fetch = async (...args) => {
        const response = await originalFetch(...args);

        if (isSameOrigin(args[0])) {
            recoverFromExpiredSession(response.status, options);
        }

        return response;
    };

    router.on('httpException', (event) => {
        if (recoverFromExpiredSession(event.detail.response.status, options)) {
            // Cancels Inertia's error modal. The page is on its way out.
            return false;
        }
    });
}

function isSameOrigin(input: RequestInfo | URL): boolean {
    try {
        const url =
            typeof input === 'string' || input instanceof URL
                ? input
                : input.url;

        return (
            new URL(url, window.location.href).origin === window.location.origin
        );
    } catch {
        // An input we cannot resolve is not one we are willing to act on.
        return false;
    }
}
