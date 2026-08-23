import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const toastError = vi.fn();

async function freshModules() {
    vi.resetModules();
    toastError.mockClear();

    const listeners: Record<string, (e: unknown) => void> = {};

    vi.doMock('@inertiajs/react', () => ({
        router: {
            on: (name: string, cb: (e: unknown) => void) => {
                listeners[name] = cb;

                return () => delete listeners[name];
            },
        },
    }));
    vi.doMock('sonner', () => ({ toast: { error: toastError } }));

    const tracker = await import('./unattended-requests');
    // The departure flag lives at module scope and is one-way, so the pair has to
    // be imported fresh alongside the listener it now guards.
    const leavePageModule = await import('./leave-page');
    const { installFailedNavigationToast } =
        await import('./failed-navigation-toast');

    tracker.trackUnattendedRequests();
    installFailedNavigationToast();

    return { listeners, leavePage: leavePageModule.leavePage };
}

function failure(url: string) {
    return { detail: { error: { url } } };
}

function polling(url: string) {
    return {
        detail: {
            visit: { url: new URL(url), prefetch: false, poll: true },
        },
    };
}

function prefetching(url: string) {
    return { detail: { visit: { url: new URL(url) } } };
}

describe('installFailedNavigationToast', () => {
    const realLocation = window.location;

    beforeEach(() => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: { href: 'https://whisper.money/onboarding' },
        });
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: realLocation,
        });
    });

    it('tells the user when a navigation they asked for died on the network', async () => {
        const { listeners } = await freshModules();

        listeners.networkError?.(failure('https://whisper.money/budgets'));

        expect(toastError).toHaveBeenCalledOnce();
    });

    it('says nothing about a page we only guessed at', async () => {
        const { listeners } = await freshModules();

        listeners.prefetching?.(prefetching('https://whisper.money/cashflow'));
        listeners.networkError?.(failure('https://whisper.money/cashflow'));

        expect(toastError).not.toHaveBeenCalled();
    });

    it('speaks up once the user commits to the page we guessed at', async () => {
        const { listeners } = await freshModules();

        listeners.prefetching?.(prefetching('https://whisper.money/cashflow'));
        listeners.before?.({
            detail: {
                visit: {
                    url: new URL('https://whisper.money/cashflow'),
                    prefetch: false,
                },
            },
        });
        listeners.networkError?.(failure('https://whisper.money/cashflow'));

        expect(toastError).toHaveBeenCalledOnce();
    });

    it('says nothing about a poll the user never asked for', async () => {
        const { listeners } = await freshModules();

        listeners.before?.(polling('https://whisper.money/onboarding'));
        listeners.networkError?.(failure('https://whisper.money/onboarding'));

        expect(toastError).not.toHaveBeenCalled();
    });

    it('speaks up when the lost beats keep coming', async () => {
        const { listeners } = await freshModules();

        // A connection that is actually gone, on a page where the poll is the only
        // thing happening: nothing else would ever tell the user.
        for (let beat = 0; beat < 3; beat++) {
            listeners.before?.(polling('https://whisper.money/onboarding'));
            listeners.networkError?.(
                failure('https://whisper.money/onboarding'),
            );
        }

        expect(toastError).toHaveBeenCalledOnce();
    });

    it('forgets the lost beats as soon as something gets through', async () => {
        const { listeners } = await freshModules();

        for (let beat = 0; beat < 2; beat++) {
            listeners.before?.(polling('https://whisper.money/onboarding'));
            listeners.networkError?.(
                failure('https://whisper.money/onboarding'),
            );
        }

        listeners.success?.({ detail: {} });

        for (let beat = 0; beat < 2; beat++) {
            listeners.before?.(polling('https://whisper.money/onboarding'));
            listeners.networkError?.(
                failure('https://whisper.money/onboarding'),
            );
        }

        expect(toastError).not.toHaveBeenCalled();
    });

    it('speaks up once the user asks for the page a poll was refreshing', async () => {
        const { listeners } = await freshModules();

        listeners.before?.(polling('https://whisper.money/onboarding'));
        listeners.before?.({
            detail: {
                visit: {
                    url: new URL('https://whisper.money/onboarding'),
                    prefetch: false,
                },
            },
        });
        listeners.networkError?.(failure('https://whisper.money/onboarding'));

        expect(toastError).toHaveBeenCalledOnce();
    });

    it('speaks up for a real reload that a poll tick overlapped', async () => {
        const { listeners } = await freshModules();

        // Inertia does not cancel an in-flight visit to the page you are already
        // on, so the poll keeps ticking underneath a reload the user asked for.
        listeners.before?.({
            detail: {
                visit: {
                    url: new URL('https://whisper.money/settings/connections'),
                    prefetch: false,
                },
            },
        });
        listeners.before?.(
            polling('https://whisper.money/settings/connections'),
        );
        listeners.networkError?.(
            failure('https://whisper.money/settings/connections'),
        );

        expect(toastError).toHaveBeenCalledOnce();
    });

    it('stops attributing a poll once its window has passed', async () => {
        vi.useFakeTimers();

        try {
            const { listeners } = await freshModules();

            // The poll stopped - the step moved on, the page unmounted - so the
            // next failure at that url belongs to whoever asked for it next.
            listeners.before?.(polling('https://whisper.money/onboarding'));
            vi.setSystemTime(Date.now() + 31_000);
            listeners.networkError?.(
                failure('https://whisper.money/onboarding'),
            );

            expect(toastError).toHaveBeenCalledOnce();
        } finally {
            vi.useRealTimers();
        }
    });

    it('still speaks up when the error carries no url', async () => {
        const { listeners } = await freshModules();

        listeners.networkError?.({ detail: { error: {} } });

        expect(toastError).toHaveBeenCalledOnce();
    });

    it('says nothing about a request our own departure aborted', async () => {
        const { listeners, leavePage } = await freshModules();

        // What tapping a bank on the onboarding create-account step does, while
        // that step's 4s poll is in flight.
        leavePage('https://bank.example/authorize');
        listeners.networkError?.(
            failure('https://whisper.money/onboarding?step=create-account'),
        );

        expect(toastError).not.toHaveBeenCalled();
    });

    it('speaks up again once it is clear we stayed', async () => {
        const { listeners, leavePage } = await freshModules();

        leavePage('https://bank.example/authorize');
        // An iOS PWA hands the redirect to Safari and keeps its own page alive.
        document.dispatchEvent(new Event('visibilitychange'));
        listeners.networkError?.(
            failure('https://whisper.money/onboarding?step=create-account'),
        );

        expect(toastError).toHaveBeenCalledOnce();
    });
});
