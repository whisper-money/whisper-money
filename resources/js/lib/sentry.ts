import type { Event } from '@sentry/react';
import { isChunkLoadError } from './chunk-load-recovery';
import { isLeavingPage } from './leave-page';

const CLONE_ERROR_MESSAGE_PATTERN =
    /object (can not|could not|couldn't|can't) be cloned/i;
// Transport-level failures only, so a genuine crash during a page unload is
// still reported. Covers axios/XHR ("Network Error", "Request aborted"),
// Inertia's own wrapper ("Network error") and fetch in both engines
// ("Failed to fetch" in Blink, "Load failed" in WebKit).
const ABORTED_REQUEST_MESSAGE_PATTERN =
    /^(network error|request aborted|failed to fetch|load failed)$/i;
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
export function isAbortedByPageLeaveNoise(event: Event): boolean {
    if (!isLeavingPage()) {
        return false;
    }

    return (
        event.exception?.values?.some((exception) =>
            ABORTED_REQUEST_MESSAGE_PATTERN.test(
                (exception.value ?? '').trim(),
            ),
        ) ?? false
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
