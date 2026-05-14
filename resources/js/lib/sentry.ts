import type { Event } from '@sentry/react';
import { isChunkLoadError } from './chunk-load-recovery';

const CLONE_ERROR_MESSAGE_PATTERN =
    /object (can not|could not|couldn't|can't) be cloned/i;
const WEBVIEW_LAYOUT_SCRIPT_GLOBALS = ['currentInset', 'CONFIG'];
const WEBVIEW_LAYOUT_SCRIPT_FUNCTIONS = [
    'init',
    'updateGapFiller',
    'updateFooterPositions',
];

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

export function isInjectedWebViewLayoutScriptNoise(event: Event): boolean {
    return (
        event.exception?.values?.some((exception) => {
            const exceptionType = exception.type ?? '';
            const exceptionValue = exception.value ?? '';
            const frames = exception.stacktrace?.frames ?? [];

            if (exceptionType !== 'ReferenceError') {
                return false;
            }

            if (
                !WEBVIEW_LAYOUT_SCRIPT_GLOBALS.some((globalName) =>
                    exceptionValue.includes(globalName),
                )
            ) {
                return false;
            }

            return frames.some((frame) =>
                [frame.function, frame.filename].some((value) => {
                    if (!value) {
                        return false;
                    }

                    return WEBVIEW_LAYOUT_SCRIPT_FUNCTIONS.some(
                        (functionName) => value.includes(functionName),
                    );
                }),
            );
        }) ?? false
    );
}
