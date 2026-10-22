import { afterEach, describe, expect, it, vi } from 'vitest';

import { detectMobilePlatform, isInstalledPwa } from './pwa';

const DESKTOP_CHROME_UA =
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const ANDROID_CHROME_UA =
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const IPHONE_SAFARI_UA =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

function fakeWindow({
    userAgent,
    displayMode = 'browser',
    standalone,
    platform,
    maxTouchPoints,
}: {
    userAgent: string;
    displayMode?: 'browser' | 'standalone' | 'fullscreen';
    standalone?: boolean;
    platform?: string;
    maxTouchPoints?: number;
}) {
    return {
        matchMedia: (query: string) => ({
            matches: query === `(display-mode: ${displayMode})`,
        }),
        navigator: { userAgent, standalone, platform, maxTouchPoints },
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('isInstalledPwa', () => {
    it('is false in a plain desktop browser', () => {
        expect(
            isInstalledPwa(fakeWindow({ userAgent: DESKTOP_CHROME_UA })),
        ).toBe(false);
    });

    it('is false in a desktop browser in fullscreen', () => {
        expect(
            isInstalledPwa(
                fakeWindow({
                    userAgent: DESKTOP_CHROME_UA,
                    displayMode: 'fullscreen',
                }),
            ),
        ).toBe(false);
    });

    it('is false in an Android Chrome tab', () => {
        expect(
            isInstalledPwa(fakeWindow({ userAgent: ANDROID_CHROME_UA })),
        ).toBe(false);
    });

    it('is false in an iPhone Safari tab', () => {
        expect(
            isInstalledPwa(
                fakeWindow({ userAgent: IPHONE_SAFARI_UA, standalone: false }),
            ),
        ).toBe(false);
    });

    it('is true in an Android app installed in standalone', () => {
        expect(
            isInstalledPwa(
                fakeWindow({
                    userAgent: ANDROID_CHROME_UA,
                    displayMode: 'standalone',
                }),
            ),
        ).toBe(true);
    });

    it('is true in an Android WebAPK minted under the fullscreen manifest', () => {
        expect(
            isInstalledPwa(
                fakeWindow({
                    userAgent: ANDROID_CHROME_UA,
                    displayMode: 'fullscreen',
                }),
            ),
        ).toBe(true);
    });

    it('is true in an iOS home-screen app that reports navigator.standalone', () => {
        expect(
            isInstalledPwa(
                fakeWindow({ userAgent: IPHONE_SAFARI_UA, standalone: true }),
            ),
        ).toBe(true);
    });

    it('is true in an iOS home-screen app that matches the standalone display mode', () => {
        expect(
            isInstalledPwa(
                fakeWindow({
                    userAgent: IPHONE_SAFARI_UA,
                    displayMode: 'standalone',
                }),
            ),
        ).toBe(true);
    });

    it('is true in a desktop app installed in standalone', () => {
        expect(
            isInstalledPwa(
                fakeWindow({
                    userAgent: DESKTOP_CHROME_UA,
                    displayMode: 'standalone',
                }),
            ),
        ).toBe(true);
    });

    it('is false on the server, where there is no window', () => {
        vi.stubGlobal('window', undefined);

        expect(isInstalledPwa()).toBe(false);
    });
});

describe('detectMobilePlatform', () => {
    it.each([
        [{ userAgent: ANDROID_CHROME_UA }, 'android'],
        [{ userAgent: IPHONE_SAFARI_UA }, 'ios'],
        [
            {
                userAgent: DESKTOP_CHROME_UA,
                platform: 'MacIntel',
                maxTouchPoints: 5,
            },
            'ios',
        ],
        [
            {
                userAgent: DESKTOP_CHROME_UA,
                platform: 'MacIntel',
                maxTouchPoints: 0,
            },
            null,
        ],
    ] as const)('reads %j as %s', (navigator, platform) => {
        expect(detectMobilePlatform(navigator)).toBe(platform);
    });

    it('is null on the server, where there is no navigator', () => {
        vi.stubGlobal('navigator', undefined);

        expect(detectMobilePlatform()).toBeNull();
    });
});
