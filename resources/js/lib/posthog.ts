import posthog from 'posthog-js';

const isPostHogEnabled = (): boolean => {
    const enabled = import.meta.env.VITE_POSTHOG_ENABLED;
    if (enabled === undefined || enabled === '') {
        return true;
    }
    return enabled === 'true' || enabled === '1';
};

const getPostHogApiKey = (): string | undefined => {
    return import.meta.env.VITE_POSTHOG_API_KEY;
};

const getPostHogHost = (): string => {
    return import.meta.env.VITE_POSTHOG_HOST || 'https://t.whisper.money';
};

const getPostHogUiHost = (): string => {
    return import.meta.env.VITE_POSTHOG_UI_HOST || 'https://eu.posthog.com';
};

export const isPostHogSessionRecordingEnabled = (): boolean => {
    const enabled = import.meta.env.VITE_POSTHOG_SESSION_RECORDING_ENABLED;
    return enabled === 'true' || enabled === '1';
};

/**
 * Every call into the PostHog SDK, wrapped so it cannot take the page with it.
 *
 * The SDK reaches for browser APIs that are not always there to be reached.
 * A hardened Firefox profile makes `crypto.getRandomValues` throw
 * `OperationError`, which kills `posthog.init` mid-flight (PHP-LARAVEL-5J); a
 * browser blocking site data throws `SecurityError` on the *read* of
 * `window.localStorage`, which kills `posthog.reset` (PHP-LARAVEL-5V). Neither
 * lands somewhere harmless: `initializePostHog` runs at module scope in
 * app.tsx, so a throw there happens before React mounts and white-screens the
 * app, and the other three run inside handlers that were doing real work — the
 * upgrade dialog captures an event on its way to checkout.
 *
 * The failure is swallowed rather than reported. These are browser-environment
 * conditions we cannot act on, and they have already cost us two Sentry issues
 * of pure noise; analytics is never worth a broken page. Guarding here is the
 * only lever we have, since the storage and crypto access that throws is the
 * SDK's own, inside the bundle.
 *
 * The try/catch is doing the real work and there is nothing to "simplify" away:
 * the SDK throws synchronously from inside these calls, so no guard around them
 * can see it coming. Same spirit as ./safe-storage.ts and ./media-query.ts,
 * which exist for the same class of crash.
 */
function withPostHog(call: () => void): void {
    if (typeof window === 'undefined' || !isPostHogEnabled()) {
        return;
    }

    try {
        call();
    } catch (error) {
        // A browser that will not let the SDK run is a browser we collect no
        // analytics from. The page carries on.
        //
        // Loud in development only: the two conditions above are nothing a
        // developer can fix, but a genuinely broken integration — a bad key, a
        // blocked host, a misused SDK method — lands in the same catch and
        // would otherwise be invisible while working on it.
        if (import.meta.env.DEV) {
            console.warn('[PostHog] Call failed and was ignored.', error);
        }
    }
}

export function initializePostHog(): void {
    withPostHog(() => {
        const apiKey = getPostHogApiKey();
        if (!apiKey) {
            console.warn(
                '[PostHog] API key not provided. PostHog will not be initialized.',
            );
            return;
        }

        posthog.init(apiKey, {
            api_host: getPostHogHost(),
            ui_host: getPostHogUiHost(),
            person_profiles: 'always',
            disable_session_recording: !isPostHogSessionRecordingEnabled(),
            loaded: () => {
                if (import.meta.env.DEV) {
                    console.log('[PostHog] Initialized successfully');
                }
            },
        });
    });
}

export function identifyUser(
    userId: string,
    properties?: Record<string, unknown>,
): void {
    withPostHog(() => posthog.identify(userId, properties));
}

export function resetPostHog(): void {
    withPostHog(() => posthog.reset());
}

export function captureEvent(
    eventName: string,
    properties?: Record<string, unknown>,
): void {
    withPostHog(() => posthog.capture(eventName, properties));
}
