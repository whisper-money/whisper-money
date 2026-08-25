import { afterAll, describe, expect, it, vi } from 'vitest';

const reload = vi.fn();
const realFetch = window.fetch;

type Listener = (event: unknown) => unknown;

async function freshModules(networkFetch?: typeof window.fetch) {
    vi.resetModules();
    reload.mockClear();
    window.fetch = networkFetch ?? realFetch;

    const listeners: Record<string, Listener> = {};
    const interceptors: ((error: unknown) => unknown)[] = [];

    vi.doMock('@inertiajs/react', () => ({
        router: {
            on: (name: string, callback: Listener) => {
                listeners[name] = callback;

                return () => delete listeners[name];
            },
        },
    }));
    vi.doMock('axios', () => ({
        default: {
            interceptors: {
                response: {
                    use: (_ok: unknown, onError: (error: unknown) => unknown) =>
                        interceptors.push(onError),
                },
            },
            isAxiosError: (error: unknown) =>
                Boolean((error as { isAxiosError?: boolean }).isAxiosError),
        },
    }));

    const { installSessionExpiryRecovery } =
        await import('./session-expiry-recovery');

    installSessionExpiryRecovery({ reload });

    return {
        listeners,
        rejectAxios: async (status: number | undefined) => {
            const error = { isAxiosError: true, response: { status } };

            await expect(interceptors[0](error)).rejects.toBe(error);
        },
    };
}

/** A `fetch` for {@link freshModules} to wrap, standing in for the network. */
function answering(status: number, body: string | null = null) {
    return (async () =>
        new Response(body, { status })) as unknown as typeof window.fetch;
}

describe('installSessionExpiryRecovery', () => {
    afterAll(() => {
        window.fetch = realFetch;
    });

    it('reloads when an axios request comes back with a dead CSRF token', async () => {
        const { rejectAxios } = await freshModules();

        await rejectAxios(419);

        expect(reload).toHaveBeenCalledOnce();
    });

    it('reloads when an axios request expecting JSON finds no session', async () => {
        const { rejectAxios } = await freshModules();

        await rejectAxios(401);

        expect(reload).toHaveBeenCalledOnce();
    });

    it('leaves other failures alone', async () => {
        const { rejectAxios } = await freshModules();

        // A 403 is a permission the user does not have and a 422 is their input:
        // both answered by a live session.
        await rejectAxios(403);
        await rejectAxios(422);
        await rejectAxios(500);
        await rejectAxios(undefined);

        expect(reload).not.toHaveBeenCalled();
    });

    it('reloads only once when several requests expire together', async () => {
        const { rejectAxios } = await freshModules();

        await rejectAxios(419);
        await rejectAxios(419);
        await rejectAxios(401);

        expect(reload).toHaveBeenCalledOnce();
    });

    // What the ~12 call sites building their own `X-XSRF-TOKEN` header go
    // through, none of which was edited.
    it('reloads on a same-origin fetch that lost its token', async () => {
        await freshModules(answering(419));

        const response = await window.fetch('/onboarding/rules');

        expect(response.status).toBe(419);
        expect(reload).toHaveBeenCalledOnce();
    });

    it('passes the response through untouched', async () => {
        await freshModules(answering(200, '{"rules_created":5}'));

        const response = await window.fetch('/onboarding/rules');

        await expect(response.json()).resolves.toEqual({ rules_created: 5 });
        expect(reload).not.toHaveBeenCalled();
    });

    it('ignores a cross-origin fetch answering 401 for its own reasons', async () => {
        await freshModules(answering(401));

        // What partials/header.tsx does: the GitHub API's auth is not ours.
        await window.fetch('https://api.github.com/repos/whisper-money');

        expect(reload).not.toHaveBeenCalled();
    });

    it('reloads instead of showing Inertia its error page', async () => {
        const { listeners } = await freshModules();

        expect(
            listeners.httpException?.({
                detail: { response: { status: 419 } },
            }),
        ).toBe(false);
        expect(reload).toHaveBeenCalledOnce();
    });

    it('lets Inertia handle the exceptions that are not an expired session', async () => {
        const { listeners } = await freshModules();

        expect(
            listeners.httpException?.({
                detail: { response: { status: 500 } },
            }),
        ).toBeUndefined();
        expect(reload).not.toHaveBeenCalled();
    });
});
