import { describe, expect, it } from 'vitest';
import {
    absoluteUrl,
    browserHandoff,
    chromeIntentUrl,
} from './open-in-browser';

describe('chromeIntentUrl', () => {
    it('opens the same page in Chrome, falling back to the plain URL', () => {
        expect(
            chromeIntentUrl(
                'https://app.test/open-banking/connections/c-1/reconnect?from=pwa',
            ),
        ).toBe(
            'intent://app.test/open-banking/connections/c-1/reconnect?from=pwa' +
                '#Intent;scheme=https;package=com.android.chrome;' +
                'S.browser_fallback_url=https%3A%2F%2Fapp.test%2Fopen-banking%2Fconnections%2Fc-1%2Freconnect%3Ffrom%3Dpwa;end',
        );
    });

    it('keeps a port in the host', () => {
        expect(chromeIntentUrl('https://app.test:8443/onboarding')).toMatch(
            /^intent:\/\/app\.test:8443\/onboarding#Intent;/,
        );
    });
});

describe('browserHandoff', () => {
    it('opens Chrome on Android', () => {
        expect(browserHandoff('android')).toBe('open');
    });

    it('falls back to copying the link elsewhere', () => {
        expect(browserHandoff('ios')).toBe('copy');
        expect(browserHandoff(null)).toBe('copy');
    });
});

describe('absoluteUrl', () => {
    it('resolves a path against the current origin', () => {
        expect(absoluteUrl('/settings/connections')).toBe(
            `${window.location.origin}/settings/connections`,
        );
    });

    it('leaves a full URL alone', () => {
        expect(absoluteUrl('https://app.test/x')).toBe('https://app.test/x');
    });
});
