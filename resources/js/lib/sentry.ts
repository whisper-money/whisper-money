import type { Event } from '@sentry/react';
import { isChunkLoadError } from './chunk-load-recovery';
import { isLeavingPage } from './leave-page';
import { wasUnattended } from './unattended-requests';

const CLONE_ERROR_MESSAGE_PATTERN =
    /object (can not|could not|couldn't|can't) be cloned/i;
// Transport-level failures only, so a genuine crash during a page unload is
// still reported. Covers axios/XHR ("Network Error", "Request aborted") and
// fetch in both engines ("Failed to fetch" in Blink, "Load failed" in WebKit).
//
// The optional parenthesised tail is Inertia's: HttpError's constructor appends
// the request URL, so its own wrapper arrives as
// `Network error (https://whisper.money/onboarding)`. Matching that tail
// explicitly rather than loosening to a prefix keeps "Failed to fetch
// dynamically imported module: …" out — that is a chunk load, not an abort.
const ABORTED_REQUEST_MESSAGE_PATTERN =
    /^(network error|request aborted|failed to fetch|load failed)( \(.+\))?$/i;
// A strict subset of the pattern above, capturing the URL rather than just allowing
// it: that tail is the only place the *failed* request's URL survives as far as
// beforeSend, since Sentry's `url` tag is the page the user was on. Depends on
// Inertia's internal message format, which has no stability contract — if it changes,
// this stops matching and the noise comes back, which is the safe direction.
const INERTIA_NETWORK_ERROR_PATTERN = /^network error \((.+)\)$/i;
const FACEBOOK_IAB_JAVA_OBJECT_GONE_PATTERN =
    /Error invoking .+: Java object is gone/i;
const SAFARI_CASHBACK_EXTENSION_PATTERN = /response\.cashbackReminder/i;
// Office 365 / Outlook "Safe Links" scans links before a user follows them, and
// its injected bridge object rejects a promise with this literal string. The
// `Id:`/`MethodName:`/`ParamCount:` triple is the whole fingerprint — the event
// arrives as an `UnhandledRejection` with no stacktrace, so the message is all
// there is to match on. Anchoring on the triple rather than on "Non-Error promise
// rejection captured" keeps our own rejected non-Error values reportable; if the
// scanner ever changes the format this stops matching and the noise comes back,
// which is the safe direction.
const OUTLOOK_SAFE_LINKS_PATTERN =
    /Object Not Found Matching Id:\d+, MethodName:\w+, ParamCount:\d+/i;
const BROWSER_EXTENSION_URL_PATTERN =
    /^(chrome-extension|moz-extension|safari-web-extension|safari-extension|ms-browser-extension):\/\//i;
const LOOPBACK_IPV4_PATTERN = /^127(\.\d{1,3}){3}$/;

export function isChunkLoadErrorEvent(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) =>
            isChunkLoadError(
                `${exception.type ?? ''}: ${exception.value ?? ''}`,
            ),
        ) ?? false
    );
}

export function isPostMessageDataCloneNoise(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) => {
            const exceptionType = exception.type ?? '';
            const exceptionValue = exception.value ?? '';
            const frames = exception.stacktrace?.frames ?? [];

            return (
                exceptionType === 'DataCloneError' &&
                CLONE_ERROR_MESSAGE_PATTERN.test(exceptionValue) &&
                frames.some((frame) =>
                    [frame.function, frame.filename, frame.module].some(
                        (value) => value?.includes('postMessage'),
                    ),
                )
            );
        }) ?? false
    );
}

export function isFacebookInAppBrowserJavaBridgeNoise(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) => {
            const exceptionValue = exception.value ?? '';
            const frames = exception.stacktrace?.frames ?? [];

            return (
                FACEBOOK_IAB_JAVA_OBJECT_GONE_PATTERN.test(exceptionValue) &&
                frames.some((frame) =>
                    [frame.filename, frame.module].some((value) =>
                        value?.includes(
                            'iabjs://navigation_performance_logger_android',
                        ),
                    ),
                )
            );
        }) ?? false
    );
}

export function isBrowserExtensionNoise(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) => {
            const frames = exception.stacktrace?.frames ?? [];
            const crashingFrame = [...frames]
                .reverse()
                .find((frame) => frame.filename ?? frame.module);

            if (!crashingFrame) {
                return false;
            }

            return [crashingFrame.filename, crashingFrame.module].some(
                (value) =>
                    value !== undefined &&
                    BROWSER_EXTENSION_URL_PATTERN.test(value),
            );
        }) ?? false
    );
}

/**
 * Requests killed by a full page navigation we started ourselves.
 *
 * {@link leavePage} aborts whatever was in flight, and the browser hands that
 * abort to the caller as a transport failure. The request didn't fail — the page
 * it belonged to went away — so reporting it buries the connection failures that
 * really did happen to a user who stayed put.
 */
export function isPageLeaveAbortNoise(event: Event): boolean {
    if (!isLeavingPage()) {
        return false;
    }

    return (
        event.exception?.values?.some((exception) =>
            ABORTED_REQUEST_MESSAGE_PATTERN.test(exception.value ?? ''),
        ) ?? false
    );
}

/**
 * A request nobody asked for that failed.
 *
 * Every sidebar item is a `<Link prefetch>`, so hovering the nav fetches a page the
 * user has not committed to. When one fails there is nothing to fix and nothing the
 * user notices - the next real click just makes a real request, and Inertia has
 * already dropped the dead entry so the click is not wedged. Reporting it buries the
 * navigations that genuinely failed on someone who was waiting for one.
 *
 * A background poll is the same story on a timer: the onboarding create-account step
 * asks every 4s whether a bank finished connecting elsewhere, so a single beat lost
 * to a flaky connection says nothing an issue could act on. The poll four seconds
 * later is the retry.
 */
export function isUnattendedRequestNoise(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) => {
            const failedUrl = INERTIA_NETWORK_ERROR_PATTERN.exec(
                exception.value ?? '',
            )?.[1];

            return failedUrl !== undefined && wasUnattended(failedUrl);
        }) ?? false
    );
}

export function isSafariCashbackExtensionNoise(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) => {
            const exceptionValue = exception.value ?? '';
            const frames = exception.stacktrace?.frames ?? [];

            return (
                SAFARI_CASHBACK_EXTENSION_PATTERN.test(exceptionValue) &&
                frames.some(
                    (frame) =>
                        frame.function === 'onResponse' &&
                        frame.filename === 'webkit-masked-url://hidden/',
                )
            );
        }) ?? false
    );
}

export function isOutlookSafeLinksNoise(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) =>
            OUTLOOK_SAFE_LINKS_PATTERN.test(exception.value ?? ''),
        ) ?? false
    );
}

/**
 * Whether the page is served from this machine rather than from whisper.money.
 *
 * The production gate on `MODE` only says how the bundle was built. Any local
 * `bun run build` bakes in `MODE === 'production'` and, through `envPrefix`, the
 * DSN from the local `.env` — so a built bundle served by `php artisan serve`
 * reported to Sentry as production. That is what agents' headless QA runs do,
 * on `http://127.0.0.1:<port>`, and `composer run dev` sits behind portless on
 * `dev.whisper.money.localhost`; FRONTEND-Y was seven events from those and
 * none from a real user. Covers `localhost` and its `*.localhost` subdomains,
 * the whole 127.0.0.0/8 loopback block, IPv6 loopback (bracketed, as
 * `location.hostname` gives it) and `0.0.0.0`, which only a dev server answers.
 * LAN addresses are left reporting on purpose: a self-hosted install looks the same.
 */
export function isLocalHostname(hostname: string): boolean {
    const normalizedHostname = hostname.toLowerCase().replace(/\.$/, '');

    return (
        normalizedHostname === 'localhost' ||
        normalizedHostname.endsWith('.localhost') ||
        LOOPBACK_IPV4_PATTERN.test(normalizedHostname) ||
        normalizedHostname === '[::1]' ||
        normalizedHostname === '::1' ||
        normalizedHostname === '0.0.0.0'
    );
}
