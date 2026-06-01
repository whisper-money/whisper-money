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

export function initializePostHog(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!isPostHogEnabled()) {
        return;
    }

    const apiKey = getPostHogApiKey();
    if (!apiKey) {
        console.warn(
            '[PostHog] API key not provided. PostHog will not be initialized.',
        );
        return;
    }

    const host = getPostHogHost();

    posthog.init(apiKey, {
        api_host: host,
        ui_host: getPostHogUiHost(),
        person_profiles: 'always',
        disable_session_recording: !isPostHogSessionRecordingEnabled(),
        loaded: () => {
            if (import.meta.env.DEV) {
                console.log('[PostHog] Initialized successfully');
            }
        },
    });
}

export function identifyUser(
    userId: string,
    properties?: Record<string, unknown>,
): void {
    if (typeof window === 'undefined' || !isPostHogEnabled()) {
        return;
    }

    posthog.identify(userId, properties);
}

export function resetPostHog(): void {
    if (typeof window === 'undefined' || !isPostHogEnabled()) {
        return;
    }

    posthog.reset();
}

export function captureEvent(
    eventName: string,
    properties?: Record<string, unknown>,
): void {
    if (typeof window === 'undefined' || !isPostHogEnabled()) {
        return;
    }

    posthog.capture(eventName, properties);
}
