import type { User } from '@/types';
import * as Sentry from '@sentry/react';

export interface SentryConfig {
    dsn?: string;
    enabled?: string;
    environment: string;
    isProduction: boolean;
    tracesSampleRate?: string;
    tracePropagationTargets?: string;
}

const booleanFromEnv = (
    value: string | undefined,
    fallback: boolean,
): boolean => {
    if (value === undefined || value === '') {
        return fallback;
    }

    return value === 'true' || value === '1';
};

const numberFromEnv = (value: string | undefined, fallback: number): number => {
    if (value === undefined || value === '') {
        return fallback;
    }

    const parsed = Number.parseFloat(value);

    if (Number.isNaN(parsed)) {
        return fallback;
    }

    return parsed;
};

export const getTracePropagationTargets = (
    configuredTargets?: string,
): Array<string | RegExp> => {
    if (!configuredTargets?.trim()) {
        return ['localhost'];
    }

    return configuredTargets
        .split(',')
        .map((target) => target.trim())
        .filter(Boolean);
};

export const getSentryOptions = (
    config: SentryConfig,
): Parameters<typeof Sentry.init>[0] | null => {
    const dsn = config.dsn?.trim();

    if (!dsn) {
        return null;
    }

    if (!booleanFromEnv(config.enabled, config.isProduction)) {
        return null;
    }

    return {
        dsn,
        sendDefaultPii: true,
        environment: config.environment,
        integrations: [Sentry.browserTracingIntegration()],
        tracesSampleRate: numberFromEnv(config.tracesSampleRate, 1.0),
        tracePropagationTargets: getTracePropagationTargets(
            config.tracePropagationTargets,
        ),
        enableLogs: true,
    };
};

export function initializeSentry(): void {
    const options = getSentryOptions({
        dsn: import.meta.env.VITE_SENTRY_DSN,
        enabled: import.meta.env.VITE_SENTRY_ENABLED,
        environment:
            import.meta.env.VITE_SENTRY_ENVIRONMENT || import.meta.env.MODE,
        isProduction: import.meta.env.PROD,
        tracesSampleRate: import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE,
        tracePropagationTargets: import.meta.env
            .VITE_SENTRY_TRACE_PROPAGATION_TARGETS,
    });

    if (options === null) {
        return;
    }

    Sentry.init(options);
}

export function setSentryUser(user: User | null | undefined): void {
    if (!user) {
        Sentry.setUser(null);
        return;
    }

    Sentry.setUser({
        id: String(user.id),
        email: user.email,
    });
}
