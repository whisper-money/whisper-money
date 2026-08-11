import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The flag lives at module scope, so every case needs its own instance. Importing
 * fresh also re-registers the listeners against the current jsdom document.
 */
async function freshModule() {
    vi.resetModules();

    return import('./leave-page');
}

describe('leave-page', () => {
    const realLocation = window.location;

    beforeEach(() => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: {
                href: 'https://whisper.money/onboarding',
                reload: vi.fn(),
            },
        });
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: realLocation,
        });
    });

    it('is not leaving until something says so', async () => {
        const { isLeavingPage } = await freshModule();

        expect(isLeavingPage()).toBe(false);
    });

    it('navigates and records the departure', async () => {
        const { leavePage, isLeavingPage } = await freshModule();

        leavePage('https://bank.example/authorize');

        expect(window.location.href).toBe('https://bank.example/authorize');
        expect(isLeavingPage()).toBe(true);
    });

    it('reloads and records the departure', async () => {
        const { reloadPage, isLeavingPage } = await freshModule();

        reloadPage();

        expect(window.location.reload).toHaveBeenCalledOnce();
        expect(isLeavingPage()).toBe(true);
    });

    it('records departures it did not start, like an external link', async () => {
        const { isLeavingPage } = await freshModule();

        window.dispatchEvent(new Event('pagehide'));

        expect(isLeavingPage()).toBe(true);
    });

    it('stops leaving when the back/forward cache restores the page', async () => {
        const { leavePage, isLeavingPage } = await freshModule();

        leavePage('https://bank.example/authorize');
        window.dispatchEvent(
            new PageTransitionEvent('pageshow', {
                persisted: true,
            }),
        );

        expect(isLeavingPage()).toBe(false);
    });

    it('keeps leaving on the initial load, which is not a restore', async () => {
        const { leavePage, isLeavingPage } = await freshModule();

        leavePage('https://bank.example/authorize');
        window.dispatchEvent(
            new PageTransitionEvent('pageshow', {
                persisted: false,
            }),
        );

        expect(isLeavingPage()).toBe(true);
    });

    it('stops leaving when the page becomes visible again, as an iOS PWA does', async () => {
        const { leavePage, isLeavingPage } = await freshModule();

        leavePage('https://bank.example/authorize');

        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'hidden',
        });
        document.dispatchEvent(new Event('visibilitychange'));
        expect(isLeavingPage()).toBe(true);

        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'visible',
        });
        document.dispatchEvent(new Event('visibilitychange'));

        expect(isLeavingPage()).toBe(false);
    });
});
