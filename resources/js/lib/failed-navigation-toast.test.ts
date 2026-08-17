import { describe, expect, it, vi } from 'vitest';

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

    const tracker = await import('./prefetch-tracker');
    const { installFailedNavigationToast } =
        await import('./failed-navigation-toast');

    tracker.trackPrefetchedUrls();
    installFailedNavigationToast();

    return { listeners };
}

function failure(url: string) {
    return { detail: { error: { url } } };
}

function prefetching(url: string) {
    return { detail: { visit: { url: new URL(url) } } };
}

describe('installFailedNavigationToast', () => {
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

    it('still speaks up when the error carries no url', async () => {
        const { listeners } = await freshModules();

        listeners.networkError?.({ detail: { error: {} } });

        expect(toastError).toHaveBeenCalledOnce();
    });
});
