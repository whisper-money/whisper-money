import { beforeEach, describe, expect, it, vi } from 'vitest';

const sentry = vi.hoisted(() => ({
    browserTracingIntegration: vi.fn(() => ({ name: 'browserTracing' })),
    init: vi.fn(),
    setUser: vi.fn(),
}));

vi.mock('@sentry/react', () => sentry);

import {
    getSentryOptions,
    getTracePropagationTargets,
    setSentryUser,
} from './sentry';

describe('sentry frontend config', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('builds production options with tracing, logs, and PII enabled', () => {
        const options = getSentryOptions({
            dsn: 'https://examplePublicKey@o0.ingest.sentry.io/1',
            environment: 'production',
            isProduction: true,
        });

        expect(options).toMatchObject({
            dsn: 'https://examplePublicKey@o0.ingest.sentry.io/1',
            enableLogs: true,
            environment: 'production',
            sendDefaultPii: true,
            tracesSampleRate: 1,
        });
        expect(options?.integrations).toEqual([{ name: 'browserTracing' }]);
        expect(sentry.browserTracingIntegration).toHaveBeenCalledOnce();
    });

    it('does not initialize without a dsn', () => {
        expect(
            getSentryOptions({
                environment: 'production',
                isProduction: true,
            }),
        ).toBeNull();
    });

    it('does not initialize when explicitly disabled', () => {
        expect(
            getSentryOptions({
                dsn: 'https://examplePublicKey@o0.ingest.sentry.io/1',
                enabled: 'false',
                environment: 'production',
                isProduction: true,
            }),
        ).toBeNull();
    });

    it('uses comma-separated trace propagation targets when configured', () => {
        expect(
            getTracePropagationTargets(
                'localhost,https://whisper.money,https://whisper.money.localhost',
            ),
        ).toEqual([
            'localhost',
            'https://whisper.money',
            'https://whisper.money.localhost',
        ]);
    });

    it('sets frontend user context with id and email only', () => {
        setSentryUser({ id: 'user-id', email: 'user@example.com' } as never);

        expect(sentry.setUser).toHaveBeenCalledWith({
            id: 'user-id',
            email: 'user@example.com',
        });
    });

    it('clears frontend user context for guests', () => {
        setSentryUser(null);

        expect(sentry.setUser).toHaveBeenCalledWith(null);
    });
});
