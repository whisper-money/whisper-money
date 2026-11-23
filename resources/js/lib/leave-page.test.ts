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

    it('stops leaving when the departure it started never happened', async () => {
        vi.useFakeTimers();

        try {
            const { leavePage, isLeavingPage } = await freshModule();

            // An empty redirect url, a blocked top-level load, a bank that never
            // answers: the document stays put and visible, so neither the restore
            // nor the visibility listener can ever clear this.
            leavePage('https://bank.example/authorize');
            expect(isLeavingPage()).toBe(true);

            vi.advanceTimersByTime(10_000);

            expect(isLeavingPage()).toBe(false);
        } finally {
            vi.useRealTimers();
        }
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

    describe('Inertia location visits', () => {
        // The real router listens on `document` for `inertia:<event>`, so firing the
        // events Inertia fires exercises the actual wiring.
        function fire(name: string, detail: object, cancelable = false): void {
            document.dispatchEvent(
                new CustomEvent(`inertia:${name}`, { detail, cancelable }),
            );
        }

        function location(versionChange: boolean): void {
            fire(
                'location',
                {
                    url: new URL('https://whisper.money/dashboard'),
                    versionChange,
                },
                true,
            );
        }

        it('records the reload a deploy forces on a regular visit', async () => {
            const { trackInertiaLocationDepartures, isLeavingPage } =
                await freshModule();
            trackInertiaLocationDepartures();
            const visit = { async: false };

            fire('start', { visit });
            location(true);

            expect(isLeavingPage()).toBe(true);
        });

        it('records an external redirect', async () => {
            const { trackInertiaLocationDepartures, isLeavingPage } =
                await freshModule();
            trackInertiaLocationDepartures();

            location(false);

            expect(isLeavingPage()).toBe(true);
        });

        it('stays put when a deploy answers only an async visit, which Inertia does not reload for', async () => {
            const { trackInertiaLocationDepartures, isLeavingPage } =
                await freshModule();
            trackInertiaLocationDepartures();

            fire('start', { visit: { async: true } });
            location(true);

            expect(isLeavingPage()).toBe(false);
        });

        it('forgets regular visits once they finish', async () => {
            const { trackInertiaLocationDepartures, isLeavingPage } =
                await freshModule();
            trackInertiaLocationDepartures();
            const visit = { async: false };

            fire('start', { visit });
            fire('finish', { visit });
            location(true);

            expect(isLeavingPage()).toBe(false);
        });

        it('stops leaving when the forced reload never happened', async () => {
            vi.useFakeTimers();

            try {
                const { trackInertiaLocationDepartures, isLeavingPage } =
                    await freshModule();
                trackInertiaLocationDepartures();

                location(false);
                vi.advanceTimersByTime(10_000);

                expect(isLeavingPage()).toBe(false);
            } finally {
                vi.useRealTimers();
            }
        });
    });
});
