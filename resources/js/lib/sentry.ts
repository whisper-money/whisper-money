import type { Event } from '@sentry/react';
import { isChunkLoadError } from './chunk-load-recovery';
import { isLeavingPage } from './leave-page';
import { wasPrefetched } from './prefetch-tracker';

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
// Inertia's own wrapper appends the request URL, which is the only place the failed
// URL survives to beforeSend.
const INERTIA_NETWORK_ERROR_PATTERN = /^network error \((.+)\)$/i;
const ABORTED_REQUEST_MESSAGE_PATTERN =
    /^(network error|request aborted|failed to fetch|load failed)( \(.+\))?$/i;
const FACEBOOK_IAB_JAVA_OBJECT_GONE_PATTERN =
    /Error invoking .+: Java object is gone/i;
const SAFARI_CASHBACK_EXTENSION_PATTERN = /response\.cashbackReminder/i;
const BROWSER_EXTENSION_URL_PATTERN =
    /^(chrome-extension|moz-extension|safari-web-extension|safari-extension|ms-browser-extension):\/\//i;

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
 * A speculative request that failed.
 *
 * Every sidebar item is a `<Link prefetch>`, so hovering the nav fetches a page the
 * user has not committed to. When one fails there is nothing to fix and nothing the
 * user notices - the next real click just makes a real request, and Inertia has
 * already dropped the dead entry so the click is not wedged. Reporting it buries the
 * navigations that genuinely failed on someone who was waiting for one.
 */
export function isFailedPrefetchNoise(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) => {
            const failedUrl = INERTIA_NETWORK_ERROR_PATTERN.exec(
                exception.value ?? '',
            )?.[1];

            return failedUrl !== undefined && wasPrefetched(failedUrl);
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
