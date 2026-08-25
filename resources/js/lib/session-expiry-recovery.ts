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
 * wherever {@link \App\Services\AuthEntryPointService} sends guests, so the user
 * logs back in and lands somewhere real instead of poking a dead screen. Hence
 * no hardcoded `/login` here — that would skip `guestRedirectRoute`.
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
    // The CSRF token died with the session. What a stale tab's writes get.
    419,
    // No session left, on a request that asked for JSON — Laravel cannot answer
    // an XHR with a redirect, so it says so with a status instead.
    401,
];

let recovering = false;

interface SessionExpiryRecoveryOptions {
    reload?: () => void;
}

/**
 * Reloads if this status says the session is gone, and reports whether it did so
 * the caller can suppress whatever it would otherwise have shown.
 */
function recoverFromExpiredSession(
    status: number | undefined,
    options: SessionExpiryRecoveryOptions,
): boolean {
    if (status === undefined || recovering) {
        return false;
    }

    if (!SESSION_EXPIRED_STATUSES.includes(status)) {
        return false;
    }

    recovering = true;
    (options.reload ?? reloadPage)();

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
