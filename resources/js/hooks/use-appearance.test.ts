import { initializeTheme } from '@/hooks/use-appearance';
import { beforeEach, describe, expect, it } from 'vitest';

const themeColor = () =>
    [...document.querySelectorAll('meta[name="theme-color"]')].map((meta) => ({
        content: meta.getAttribute('content'),
        media: meta.getAttribute('media'),
    }));

describe('initializeTheme', () => {
    beforeEach(() => {
        localStorage.clear();
        document.head.innerHTML = '';

        // jsdom ships no matchMedia; the boot path needs one to get this far.
        window.matchMedia = ((query: string) => ({
            matches: false,
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
        })) as unknown as typeof window.matchMedia;
    });

    it.each([
        ['light', '#ffffff'],
        ['dark', '#1c1c1c'],
    ])('points the status bar at the %s background', (appearance, expected) => {
        localStorage.setItem('appearance', appearance);

        initializeTheme();

        expect(themeColor()).toEqual([{ content: expected, media: null }]);
    });

    it('collapses the server-rendered media pair once the theme is resolved', () => {
        document.head.innerHTML = `
            <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
            <meta name="theme-color" content="#1c1c1c" media="(prefers-color-scheme: dark)">
        `;
        localStorage.setItem('appearance', 'light');

        initializeTheme();

        expect(themeColor()).toEqual([{ content: '#ffffff', media: null }]);
    });
});
