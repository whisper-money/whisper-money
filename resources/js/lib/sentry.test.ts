import type { Event } from '@sentry/react';
import { describe, expect, it } from 'vitest';
import {
    isChunkLoadErrorEvent,
    isInjectedWebViewLayoutScriptNoise,
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

describe('isInjectedWebViewLayoutScriptNoise', () => {
    it('drops injected safe-area layout script globals', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'ReferenceError',
                        value: "Can't find variable: currentInset",
                        stacktrace: {
                            frames: [
                                {
                                    filename: '/login',
                                    function: 'init',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isInjectedWebViewLayoutScriptNoise(event)).toBe(true);
    });

    it('drops injected gap filler CONFIG references', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'ReferenceError',
                        value: "Can't find variable: CONFIG",
                        stacktrace: {
                            frames: [
                                {
                                    filename: '/login',
                                    function: 'updateFooterPositions',
                                },
                                {
                                    filename: '/login',
                                    function: 'updateGapFiller',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isInjectedWebViewLayoutScriptNoise(event)).toBe(true);
    });

    it('keeps app ReferenceError events', () => {
        const event: Event = {
            exception: {
                values: [
                    {
                        type: 'ReferenceError',
                        value: "Can't find variable: account",
                        stacktrace: {
                            frames: [
                                {
                                    filename: '/build/assets/app.js',
                                    function: 'renderAccount',
                                },
                            ],
                        },
                    },
                ],
            },
        };

        expect(isInjectedWebViewLayoutScriptNoise(event)).toBe(false);
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
