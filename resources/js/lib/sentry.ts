import type { Event } from '@sentry/react';

const CLONE_ERROR_MESSAGE_PATTERN =
    /object (can not|could not|couldn't|can't) be cloned/i;

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
