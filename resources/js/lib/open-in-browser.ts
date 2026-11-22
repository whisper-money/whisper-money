import type { MobilePlatform } from '@/lib/pwa';

/**
 * An Android intent URL that opens `url` in Chrome rather than in the
 * installed app. Android PWAs share Chrome's cookies, so the user lands there
 * still signed in. A phone without Chrome follows the fallback URL in its
 * default browser instead.
 */
export function chromeIntentUrl(url: string): string {
    const target = new URL(url);

    return (
        `intent://${target.host}${target.pathname}${target.search}` +
        '#Intent;scheme=https;package=com.android.chrome;' +
        `S.browser_fallback_url=${encodeURIComponent(target.href)};end`
    );
}

/**
 * How the installed app can hand a page over to the browser: Android can open
 * Chrome straight away; iOS (and anything else) has no way out of a
 * standalone web app, so the user gets the link to paste in Safari.
 */
export function browserHandoff(platform: MobilePlatform): 'open' | 'copy' {
    return platform === 'android' ? 'open' : 'copy';
}

/** Resolves a path against the current origin, so the handoff gets a full URL. */
export function absoluteUrl(url: string): string {
    return new URL(url, window.location.origin).href;
}
