import posthog from 'posthog-js';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    captureEvent,
    identifyUser,
    initializePostHog,
    isPostHogSessionRecordingEnabled,
    resetPostHog,
} from './posthog';

// An SDK behaving the way it behaves on the browsers that reported
// PHP-LARAVEL-5J (crypto.getRandomValues throwing OperationError inside init)
// and PHP-LARAVEL-5V (reading window.localStorage throwing SecurityError inside
// reset): every entry point throws synchronously.
vi.mock('posthog-js', () => {
    const explode = () => {
        throw new DOMException(
            'The operation failed for an operation-specific reason',
        );
    };

    return {
        default: {
            init: vi.fn(explode),
            identify: vi.fn(explode),
            reset: vi.fn(explode),
            capture: vi.fn(explode),
        },
    };
});

describe('isPostHogSessionRecordingEnabled', () => {
    it('keeps session recording disabled by default', () => {
        expect(isPostHogSessionRecordingEnabled()).toBe(false);
    });
});

describe('PostHog wrappers', () => {
    let warn: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.clearAllMocks();
        warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        // Pinned rather than inherited: the local .env disables PostHog and CI
        // has no .env at all, so without this the wrappers would no-op on one
        // machine and run on the other.
        vi.stubEnv('VITE_POSTHOG_ENABLED', 'true');
        // Without a key initializePostHog bails before reaching the SDK, so the
        // throw it has to survive would never happen.
        vi.stubEnv('VITE_POSTHOG_API_KEY', 'phc_test');
    });

    afterEach(() => {
        vi.unstubAllEnvs();
        vi.restoreAllMocks();
    });

    it.each([
        ['init', () => initializePostHog()],
        ['identify', () => identifyUser('user-1', { email: 'reader@a.test' })],
        ['reset', () => resetPostHog()],
        ['capture', () => captureEvent('upgrade_checkout_started')],
    ] as const)(
        'keeps the app alive when posthog.%s throws',
        (method, call) => {
            expect(call).not.toThrow();
            // The call still has to reach the SDK: a guard that skipped it
            // altogether would pass the assertion above and collect nothing.
            expect(posthog[method]).toHaveBeenCalled();
            // Swallowed, but not in silence while someone is developing —
            // a broken integration lands in the same catch.
            expect(warn).toHaveBeenCalled();
        },
    );

    it('leaves the SDK alone when PostHog is disabled', () => {
        vi.stubEnv('VITE_POSTHOG_ENABLED', 'false');

        initializePostHog();
        captureEvent('upgrade_checkout_started');

        expect(posthog.init).not.toHaveBeenCalled();
        expect(posthog.capture).not.toHaveBeenCalled();
    });
});
