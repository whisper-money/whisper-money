export type MobilePlatform = 'android' | 'ios' | null;

interface NavigatorLike {
    userAgent: string;
    platform?: string;
    maxTouchPoints?: number;
    standalone?: boolean;
}

interface WindowLike {
    matchMedia(query: string): { matches: boolean };
    navigator: NavigatorLike;
}

/**
 * iPadOS reports itself as `MacIntel`, so a Mac with touch points is an iPad.
 */
export function detectMobilePlatform(
    navigator: NavigatorLike | undefined = globalThis.navigator,
): MobilePlatform {
    if (!navigator) {
        return null;
    }

    const ua = navigator.userAgent;

    if (/android/i.test(ua)) {
        return 'android';
    }

    if (
        /iPad|iPhone|iPod/.test(ua) ||
        (navigator.platform === 'MacIntel' &&
            (navigator.maxTouchPoints ?? 0) > 1)
    ) {
        return 'ios';
    }

    return null;
}

/**
 * Whether the page runs inside the installed app rather than a browser tab.
 *
 * Standalone is the mode the manifest asks for, on Android and iOS alike;
 * navigator.standalone covers older iOS. Fullscreen also matches a desktop
 * browser window in fullscreen (F11, macOS fullscreen), so it only counts on
 * Android, for the installs made while the manifest asked for it (September
 * to October 2026) until Chrome updates their WebAPK. Drop that branch, and
 * its PwaTest, from 2027.
 */
export function isInstalledPwa(
    window: WindowLike | undefined = globalThis.window,
): boolean {
    if (!window) {
        return false;
    }

    if (
        window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true
    ) {
        return true;
    }

    return (
        detectMobilePlatform(window.navigator) === 'android' &&
        window.matchMedia('(display-mode: fullscreen)').matches
    );
}
