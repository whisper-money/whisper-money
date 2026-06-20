import type { Event } from '@sentry/react';
import { describe, expect, it } from 'vitest';
import {
    isBrowserExtensionNoise,
    isChunkLoadErrorEvent,
    isFacebookInAppBrowserJavaBridgeNoise,
    isPostMessageDataCloneNoise,
    isSafariCashbackExtensionNoise,
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

describe('isSafariCashbackExtensionNoise', () => {
    it('drops Safari cashback extension response handler errors', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'TypeError',
                        value: "undefined is not an object (evaluating 'response.cashbackReminder')",
                        stacktrace: {
                            frames: [
                                {
                                    filename: 'webkit-masked-url://hidden/',
                                    function: 'onResponse',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isSafariCashbackExtensionNoise(event)).toBe(true);
    });

    it('keeps cashback errors from application code', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'TypeError',
                        value: "undefined is not an object (evaluating 'response.cashbackReminder')",
                        stacktrace: {
                            frames: [
                                {
                                    filename: '/build/assets/app.js',
                                    function: 'handleResponse',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isSafariCashbackExtensionNoise(event)).toBe(false);
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

describe('isBrowserExtensionNoise', () => {
    it('drops errors thrown entirely inside a browser extension script', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'i',
                        value: 'Failed to connect to MetaMask',
                        stacktrace: {
                            frames: [
                                {
                                    filename:
                                        'chrome-extension://nkbihfbeogaeaoehlefnkodbefgpgknn/scripts/inpage.js',
                                    function: 'Object.connect',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isBrowserExtensionNoise(event)).toBe(true);
    });

    it('drops errors that crash in an injected extension script behind an app wrapper frame', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'Error',
                        value: "Cannot read properties of undefined (reading 'sendMessage')",
                        stacktrace: {
                            frames: [
                                {
                                    filename: '/build/assets/app-DTyIdEGx.js',
                                    function: 'a',
                                },
                                {
                                    filename:
                                        'chrome-extension://dmkamcknogkgcdfhhbddcghachkejeap/injectedScript.bundle.js',
                                    function: 'n',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isBrowserExtensionNoise(event)).toBe(true);
    });

    it('keeps application errors with no extension frames', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'AxiosError',
                        value: 'Network Error',
                        stacktrace: {
                            frames: [
                                {
                                    filename: '/build/assets/app-DWXGp9uF.js',
                                    function: 'Za.request',
                                },
                                {
                                    filename: '/build/assets/app-DWXGp9uF.js',
                                    function: 'T.onerror',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isBrowserExtensionNoise(event)).toBe(false);
    });

    it('keeps application errors when the extension frame is not where it crashed', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'TypeError',
                        value: 'Cannot read properties of null',
                        stacktrace: {
                            frames: [
                                {
                                    filename:
                                        'chrome-extension://abcdefghijklmnop/inject.js',
                                    function: 'wrap',
                                },
                                {
                                    filename: '/build/assets/app.js',
                                    function: 'handleClick',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isBrowserExtensionNoise(event)).toBe(false);
    });
});
