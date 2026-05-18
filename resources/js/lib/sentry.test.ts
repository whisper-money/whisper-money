import type { Event } from '@sentry/react';
import { describe, expect, it } from 'vitest';
import {
    isChunkLoadErrorEvent,
    isFacebookInAppBrowserJavaBridgeNoise,
    isPostMessageDataCloneNoise,
} from './sentry';

describe('isChunkLoadErrorEvent', () => {
    it('drops recoverable Vite dynamic import failures', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'TypeError',
                        value: 'Failed to fetch dynamically imported module: https://whisper.money/build/assets/accounts-BO3xxENF.js',
                    },
                ],
            },
        };

        expect(isChunkLoadErrorEvent(event)).toBe(true);
    });

    it('keeps unrelated TypeError events', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'TypeError',
                        value: 'Cannot read properties of undefined',
                    },
                ],
            },
        };

        expect(isChunkLoadErrorEvent(event)).toBe(false);
    });
});

describe('isFacebookInAppBrowserJavaBridgeNoise', () => {
    it('drops Facebook Android webview Java bridge shutdown errors', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'Error',
                        value: 'Error invoking postMessage: Java object is gone',
                        stacktrace: {
                            frames: [
                                {
                                    filename:
                                        'iabjs://navigation_performance_logger_android',
                                    function: 'U',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isFacebookInAppBrowserJavaBridgeNoise(event)).toBe(true);
    });

    it('keeps matching messages without the Facebook webview frame', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'Error',
                        value: 'Error invoking postMessage: Java object is gone',
                        stacktrace: {
                            frames: [
                                {
                                    filename: '/build/assets/app.js',
                                    function: 'postMessage',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isFacebookInAppBrowserJavaBridgeNoise(event)).toBe(false);
    });
});

describe('isPostMessageDataCloneNoise', () => {
    it('drops browser postMessage DataCloneError noise', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'DataCloneError',
                        value: 'The object can not be cloned.',
                        stacktrace: {
                            frames: [
                                {
                                    function: 'Window.postMessage',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isPostMessageDataCloneNoise(event)).toBe(true);
    });

    it('keeps other DataCloneError events without postMessage frames', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'DataCloneError',
                        value: 'The object can not be cloned.',
                        stacktrace: {
                            frames: [
                                {
                                    function: 'structuredClone',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isPostMessageDataCloneNoise(event)).toBe(false);
    });
});
