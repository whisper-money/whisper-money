import type { Event } from '@sentry/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    isBrowserExtensionNoise,
    isChunkLoadErrorEvent,
    isFacebookInAppBrowserJavaBridgeNoise,
    isPostMessageDataCloneNoise,
    isSafariCashbackExtensionNoise,
} from './sentry';

function exceptionEvent(type: string, value: string): Event {
    return { exception: { values: [{ type, value }] } };
}

describe('isPageLeaveAbortNoise', () => {
    // The flag lives at module scope and is deliberately one-way, so each case
    // needs its own instance of the pair rather than a reset helper.
    async function freshModules() {
        vi.resetModules();

        return {
            ...(await import('./leave-page')),
            ...(await import('./sentry')),
        };
    }

    const realLocation = window.location;

    beforeEach(() => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: { href: 'https://whisper.money/onboarding' },
        });
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: realLocation,
        });
    });

    it('keeps network failures while the user is still on the page', async () => {
        const { isPageLeaveAbortNoise } = await freshModules();

        expect(
            isPageLeaveAbortNoise(
                exceptionEvent('AxiosError', 'Network Error'),
            ),
        ).toBe(false);
    });

    it.each([
        ['AxiosError', 'Network Error'],
        ['AxiosError', 'Request aborted'],
        // Inertia's own shape: HttpError appends the request URL to the message,
        // so a bare "Network error" is not what actually reaches Sentry.
        [
            'HttpNetworkError',
            'Network error (https://whisper.money/onboarding?step=create-account)',
        ],
        ['TypeError', 'Failed to fetch'],
        ['TypeError', 'Load failed'],
    ])(
        'drops a %s "%s" caused by the navigation we started',
        async (type, value) => {
            const { leavePage, isPageLeaveAbortNoise } = await freshModules();

            leavePage('https://bank.example/authorize');

            expect(window.location.href).toBe('https://bank.example/authorize');
            expect(isPageLeaveAbortNoise(exceptionEvent(type, value))).toBe(
                true,
            );
        },
    );

    it('keeps a genuine crash that happens while leaving', async () => {
        const { leavePage, isPageLeaveAbortNoise } = await freshModules();

        leavePage('https://bank.example/authorize');

        expect(
            isPageLeaveAbortNoise(
                exceptionEvent(
                    'TypeError',
                    "undefined is not an object (evaluating 'e.features.cashflow')",
                ),
            ),
        ).toBe(false);
    });

    it('keeps a chunk load failure, which starts the same way', async () => {
        const { leavePage, isPageLeaveAbortNoise } = await freshModules();

        leavePage('https://bank.example/authorize');

        expect(
            isPageLeaveAbortNoise(
                exceptionEvent(
                    'TypeError',
                    'Failed to fetch dynamically imported module: https://whisper.money/build/assets/accounts-BO3xxENF.js',
                ),
            ),
        ).toBe(false);
    });

    it('keeps an event carrying no exception at all', async () => {
        const { leavePage, isPageLeaveAbortNoise } = await freshModules();

        leavePage('https://bank.example/authorize');

        expect(isPageLeaveAbortNoise({ message: 'Network Error' })).toBe(false);
    });
});

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

describe('isFailedPrefetchNoise', () => {
    async function freshModules() {
        vi.resetModules();
        const listeners: Record<string, (e: unknown) => void> = {};
        vi.doMock('@inertiajs/react', () => ({
            router: {
                on: (name: string, cb: (e: unknown) => void) => {
                    listeners[name] = cb;

                    return () => delete listeners[name];
                },
            },
        }));

        const tracker = await import('./prefetch-tracker');
        const sentry = await import('./sentry');
        tracker.trackPrefetchedUrls();

        return { ...tracker, ...sentry, listeners };
    }

    function networkError(url: string): Event {
        return {
            exception: {
                values: [
                    {
                        type: 'HttpNetworkError',
                        value: `Network error (${url})`,
                    },
                ],
            },
        };
    }

    it('drops the failure of a page we only guessed at', async () => {
        const { isFailedPrefetchNoise, listeners } = await freshModules();

        // Hovering the sidebar prefetches Cashflow from whatever page you are on.
        listeners.prefetching?.({
            detail: {
                visit: { url: new URL('https://whisper.money/cashflow') },
            },
        });

        expect(
            isFailedPrefetchNoise(
                networkError('https://whisper.money/cashflow'),
            ),
        ).toBe(true);
    });

    it('keeps the failure of a navigation someone was waiting for', async () => {
        const { isFailedPrefetchNoise } = await freshModules();

        // Never prefetched, so this is a click that went nowhere - the case the
        // suppression must not swallow.
        expect(
            isFailedPrefetchNoise(
                networkError('https://whisper.money/budgets'),
            ),
        ).toBe(false);
    });

    it('stops attributing a prefetch once its window has passed', async () => {
        const { isFailedPrefetchNoise, listeners } = await freshModules();

        listeners.prefetching?.({
            detail: {
                visit: { url: new URL('https://whisper.money/cashflow') },
            },
        });

        vi.setSystemTime(Date.now() + 61_000);

        expect(
            isFailedPrefetchNoise(
                networkError('https://whisper.money/cashflow'),
            ),
        ).toBe(false);
    });

    it('keeps a network error that is not Inertia-shaped', async () => {
        const { isFailedPrefetchNoise } = await freshModules();

        expect(
            isFailedPrefetchNoise({
                exception: {
                    values: [{ type: 'AxiosError', value: 'Network Error' }],
                },
            }),
        ).toBe(false);
    });
});
