import { afterEach, describe, expect, it, vi } from 'vitest';

const reload = vi.fn();

async function freshModule() {
    vi.resetModules();
    reload.mockClear();

    const listeners: Record<string, (e: unknown) => void> = {};

    vi.doMock('@inertiajs/react', () => ({
        router: {
            on: (name: string, cb: (e: unknown) => void) => {
                listeners[name] = cb;

                return () => delete listeners[name];
            },
            reload,
        },
    }));

    const { installDeferredPropsRecovery } =
        await import('./deferred-props-recovery');

    installDeferredPropsRecovery();

    return { listeners };
}

function deferredVisit(only: string[]) {
    return {
        detail: {
            visit: {
                url: new URL('https://whisper.money/dashboard'),
                only,
                deferredProps: true,
            },
        },
    };
}

describe('installDeferredPropsRecovery', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.doUnmock('@inertiajs/react');
    });

    it('asks again for the props whose request died', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        listeners.before?.(deferredVisit(['netWorthEvolution']));
        listeners.networkError?.({ detail: { error: {} } });

        // Not immediately: the connection that just dropped is given a moment.
        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(2_000);

        expect(reload).toHaveBeenCalledWith({ only: ['netWorthEvolution'] });
    });

    it('retries once and then leaves the page alone', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        listeners.before?.(deferredVisit(['netWorthEvolution']));
        listeners.networkError?.({ detail: { error: {} } });
        vi.advanceTimersByTime(2_000);

        listeners.before?.(deferredVisit(['netWorthEvolution']));
        listeners.networkError?.({ detail: { error: {} } });
        vi.advanceTimersByTime(2_000);

        expect(reload).toHaveBeenCalledOnce();
    });

    it('starts over on the next page', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        listeners.before?.(deferredVisit(['netWorthEvolution']));
        listeners.networkError?.({ detail: { error: {} } });
        vi.advanceTimersByTime(2_000);

        listeners.navigate?.({});
        listeners.before?.(deferredVisit(['transactions']));
        listeners.networkError?.({ detail: { error: {} } });
        vi.advanceTimersByTime(2_000);

        expect(reload).toHaveBeenCalledTimes(2);
        expect(reload).toHaveBeenLastCalledWith({ only: ['transactions'] });
    });

    it('stays out of the way of a request nobody deferred', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        // A save, a navigation, a poll: not our business.
        listeners.before?.({
            detail: {
                visit: {
                    url: new URL('https://whisper.money/settings/accounts'),
                    only: [],
                },
            },
        });
        listeners.networkError?.({ detail: { error: {} } });
        vi.advanceTimersByTime(2_000);

        expect(reload).not.toHaveBeenCalled();
    });
});
