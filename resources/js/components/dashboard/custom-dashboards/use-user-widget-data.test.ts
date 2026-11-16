import { type UserWidgetDefinition } from '@/types/dashboard';
import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    resolveWidgetData,
    useUserWidgetData,
    widgetAnswers,
} from './use-user-widget-data';

function definition(id: string, revision = 1): UserWidgetDefinition {
    return {
        id,
        name: id,
        revision,
        js: '',
        manifest: { size: { width: '1/2', height: 'm' } },
        last_error: null,
        last_error_at: null,
        revisions: [],
    };
}

describe('widgetAnswers', () => {
    const state = {
        status: 'ready' as const,
        widgets: {
            w: { revision: 2, data: { rows: { status: 200, body: {} } } },
        },
        refreshing: true,
    };

    it('hands over the answers read for the revision on screen', () => {
        expect(widgetAnswers(state, 'w', 2)).toEqual({
            answers: { rows: { status: 200, body: {} } },
            stale: false,
        });
    });

    it('holds back the answers read for another revision', () => {
        expect(widgetAnswers(state, 'w', 3)).toEqual({
            answers: undefined,
            stale: true,
        });
    });

    it('has nothing for a widget the request did not cover', () => {
        expect(widgetAnswers(state, 'other', 1)).toEqual({
            answers: undefined,
            stale: false,
        });
        expect(widgetAnswers({ status: 'loading' }, 'w', 2).stale).toBe(false);
    });
});

describe('resolveWidgetData', () => {
    it('hands the widget the body of each call', () => {
        const resolved = resolveWidgetData({
            accounts: { status: 200, body: { data: [{ id: 'a' }] } },
        });

        expect(resolved.data).toEqual({ accounts: { data: [{ id: 'a' }] } });
        expect(resolved.failures).toEqual([]);
        expect(resolved.truncated).toBe(false);
    });

    it('freezes the rows it hands the widget, all the way down', () => {
        const resolved = resolveWidgetData({
            tx: { status: 200, body: { data: [{ id: 't1', amount: -5 }] } },
        });
        const rows = (resolved.data.tx as { data: { amount: number }[] }).data;

        expect(Object.isFrozen(resolved.data)).toBe(true);
        expect(Object.isFrozen(rows)).toBe(true);
        expect(Object.isFrozen(rows[0])).toBe(true);
    });

    it('keeps the calls the API refused apart, with its message', () => {
        const resolved = resolveWidgetData({
            ok: { status: 200, body: {} },
            refused: {
                status: 422,
                body: { message: 'The from field must be a date.' },
            },
            down: { status: 500, body: null },
        });

        expect(Object.keys(resolved.data)).toEqual(['ok']);
        expect(resolved.failures).toEqual([
            {
                key: 'refused',
                status: 422,
                message: 'The from field must be a date.',
            },
            { key: 'down', status: 500, message: 'HTTP 500' },
        ]);
    });

    it('says when a call that reads every page hit the row cap', () => {
        const resolved = resolveWidgetData({
            rows: { status: 200, body: { data: [], truncated: true } },
            other: { status: 200, body: { data: [], truncated: false } },
        });

        expect(resolved.truncated).toBe(true);
    });
});

/** One widget's data, loaded once, with a refresh token to move on. */
async function renderRefreshable() {
    const definitions = [definition('a')];
    const hook = renderHook(
        ({ refreshToken }) =>
            useUserWidgetData('dash-1', definitions, true, { refreshToken }),
        { initialProps: { refreshToken: 0 } },
    );

    await waitFor(() => expect(hook.result.current.status).toBe('ready'));

    return hook;
}

describe('useUserWidgetData', () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        fetchMock.mockReset().mockResolvedValue({
            ok: true,
            status: 200,
            json: () =>
                Promise.resolve({
                    widgets: { a: { revision: 1, data: {} } },
                }),
        });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    it('asks once for the whole dashboard', async () => {
        const definitions = [definition('a'), definition('b')];
        const { result, rerender } = renderHook(() =>
            useUserWidgetData('dash-1', definitions, true),
        );

        expect(result.current.status).toBe('loading');
        await waitFor(() => expect(result.current.status).toBe('ready'));
        rerender();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/dashboards/dash-1/widgets/data',
        );
    });

    it('sends the range on screen', async () => {
        renderHook(() =>
            useUserWidgetData('dash-1', [definition('a')], true, {
                range: { from: '2026-10-01', to: '2026-10-31' },
            }),
        );

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(decodeURIComponent(fetchMock.mock.calls[0][0])).toContain(
            'range[from]=2026-10-01&range[to]=2026-10-31',
        );
    });

    it('asks again when the refresh token moves on, keeping the data meanwhile', async () => {
        const { result, rerender } = await renderRefreshable();
        rerender({ refreshToken: 1 });

        expect(result.current).toMatchObject({
            status: 'ready',
            refreshing: true,
        });
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await waitFor(() =>
            expect(result.current).toMatchObject({ refreshing: false }),
        );
    });

    it('keeps the data on screen when a refresh fails', async () => {
        const { result, rerender } = await renderRefreshable();
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500 });
        rerender({ refreshToken: 1 });

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await waitFor(() =>
            expect(result.current).toMatchObject({
                status: 'ready',
                refreshing: false,
            }),
        );
    });

    it('asks again when a widget changes revision', async () => {
        const { result, rerender } = renderHook(
            ({ revision }) =>
                useUserWidgetData('dash-1', [definition('a', revision)], true),
            { initialProps: { revision: 1 } },
        );

        await waitFor(() => expect(result.current.status).toBe('ready'));
        rerender({ revision: 2 });

        // The widgets already drawn keep their data meanwhile.
        expect(result.current).toMatchObject({
            status: 'ready',
            refreshing: true,
        });
        await waitFor(() =>
            expect(result.current).toMatchObject({ refreshing: false }),
        );
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('asks nothing while disabled or before the widgets load', () => {
        renderHook(() => useUserWidgetData('dash-1', undefined, true));
        renderHook(() => useUserWidgetData('dash-1', [definition('a')], false));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fails as a whole when the request does', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 429 });

        const { result } = renderHook(() =>
            useUserWidgetData('dash-1', [definition('a')], true),
        );

        await waitFor(() => expect(result.current.status).toBe('failed'));
    });
});
