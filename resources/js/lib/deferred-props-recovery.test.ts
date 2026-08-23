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

const networkError = { detail: { error: {} } };

describe('installDeferredPropsRecovery', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.doUnmock('@inertiajs/react');
    });

    it('asks again for the props whose request died', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        // The real order, which is what the first version of this got wrong:
        // Inertia fires `before` for the deferred request and `navigate` for its
        // page in the same tick, and only then can the request fail.
        listeners.before?.(deferredVisit(['dashboard']));
        listeners.navigate?.({});
        listeners.networkError?.(networkError);

        // Not immediately: the connection that just dropped is given a moment.
        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(2_000);

        expect(reload).toHaveBeenCalledWith({ only: ['dashboard'] });
    });

    it('leaves a load that got through alone', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        listeners.before?.(deferredVisit(['dashboard']));
        listeners.navigate?.({});
        listeners.finish?.(deferredVisit(['dashboard']));

        // A save blipping later on the same page is not the deferred load's
        // problem, and must not pull those queries again.
        listeners.networkError?.(networkError);
        vi.advanceTimersByTime(2_000);

        expect(reload).not.toHaveBeenCalled();
    });

    it('retries once, whatever the retry does', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        listeners.before?.(deferredVisit(['dashboard']));
        listeners.networkError?.(networkError);
        vi.advanceTimersByTime(2_000);

        // The retry is a plain reload, so Inertia does not mark it deferred and
        // nothing here is armed again by its own failure.
        listeners.before?.({
            detail: {
                visit: {
                    url: new URL('https://whisper.money/dashboard'),
                    only: ['dashboard'],
                },
            },
        });
        listeners.networkError?.(networkError);
        vi.advanceTimersByTime(2_000);

        expect(reload).toHaveBeenCalledOnce();
    });

    it('does not ask a page the user has left for its props', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        listeners.before?.(deferredVisit(['dashboard']));
        listeners.networkError?.(networkError);

        // Off to another page inside the two seconds: the reload would target
        // wherever they are now, asking for props that page does not have.
        listeners.navigate?.({});
        vi.advanceTimersByTime(2_000);

        expect(reload).not.toHaveBeenCalled();
    });

    it('stays out of the way of a request nobody deferred', async () => {
        vi.useFakeTimers();
        const { listeners } = await freshModule();

        listeners.before?.({
            detail: {
                visit: {
                    url: new URL('https://whisper.money/settings/accounts'),
                    only: [],
                },
            },
        });
        listeners.networkError?.(networkError);
        vi.advanceTimersByTime(2_000);

        expect(reload).not.toHaveBeenCalled();
    });
});
