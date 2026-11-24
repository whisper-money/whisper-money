import posthog from 'posthog-js';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { identifyUser, resetPostHog } from './posthog';

vi.mock('posthog-js', () => ({
    default: { get_property: vi.fn(), identify: vi.fn(), reset: vi.fn() },
}));

function visitorIs(state: 'anonymous' | 'identified' | undefined): void {
    vi.mocked(posthog.get_property).mockImplementation((name) =>
        name === '$user_state' ? state : undefined,
    );
}

describe('resetPostHog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubEnv('VITE_POSTHOG_ENABLED', 'true');
        vi.stubEnv('VITE_POSTHOG_API_KEY', 'phc_test');
    });

    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it('forgets a user who signed out', () => {
        visitorIs('identified');

        resetPostHog();

        expect(posthog.reset).toHaveBeenCalledOnce();
    });

    it('keeps an anonymous visitor, so signing in merges what they did as a guest', () => {
        // A drip email opened without a session: the login page loads with the
        // link's utm_* and runs the guest reset before the visitor signs in.
        visitorIs('anonymous');

        resetPostHog();
        identifyUser('user-1');

        expect(posthog.reset).not.toHaveBeenCalled();
        expect(posthog.identify).toHaveBeenCalledWith('user-1', undefined);
    });

    it('keeps a visitor the SDK has not stored yet', () => {
        visitorIs(undefined);

        resetPostHog();

        expect(posthog.reset).not.toHaveBeenCalled();
    });
});
