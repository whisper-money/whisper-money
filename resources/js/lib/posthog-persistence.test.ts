import posthog from 'posthog-js';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initializePostHog } from './posthog';

vi.mock('posthog-js', () => ({
    default: { init: vi.fn() },
}));

function initConfig(): Record<string, unknown> {
    return vi.mocked(posthog.init).mock.calls[0][1] as Record<string, unknown>;
}

/** Every cookie name the page can see right now. */
function cookieNames(): string[] {
    return document.cookie
        .split(';')
        .map((cookie) => cookie.split('=')[0].trim())
        .filter(Boolean);
}

describe('PostHog persistence', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubEnv('VITE_POSTHOG_ENABLED', 'true');
        vi.stubEnv('VITE_POSTHOG_API_KEY', 'phc_test');
    });

    afterEach(() => {
        vi.unstubAllEnvs();
        vi.restoreAllMocks();
        cookieNames().forEach((name) => {
            document.cookie = `${name}=; max-age=0; path=/`;
        });
    });

    it('keeps the visitor in local storage, never in a cookie', () => {
        initializePostHog();

        expect(initConfig().persistence).toBe('localStorage');
    });

    it('keeps the visitor in memory when local storage cannot be written', () => {
        // A browser blocking site data: the global is there, the write throws.
        // Asking the SDK for localStorage here would make it fall back to a cookie.
        vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
            throw new DOMException(
                'The operation is insecure.',
                'SecurityError',
            );
        });

        initializePostHog();

        expect(initConfig().persistence).toBe('memory');
    });

    it('expires the cookies an earlier version of the SDK left behind', () => {
        document.cookie =
            'ph_phc_test_posthog=%7B%22distinct_id%22%3A%221%22%7D; path=/';
        document.cookie = '__ph_opt_in_out_phc_test=1; path=/';
        document.cookie = 'XSRF-TOKEN=token; path=/';
        document.cookie = 'appearance=dark; path=/';

        initializePostHog();

        expect(cookieNames()).toEqual(['XSRF-TOKEN', 'appearance']);
    });
});
