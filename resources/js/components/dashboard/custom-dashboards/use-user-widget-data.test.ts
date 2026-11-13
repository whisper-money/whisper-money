import { type UserWidgetDefinition } from '@/types/dashboard';
import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resolveWidgetData, useUserWidgetData } from './use-user-widget-data';

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

describe('resolveWidgetData', () => {
    it('hands the widget the body of each call', () => {
        const resolved = resolveWidgetData({
            accounts: { status: 200, body: { data: [{ id: 'a' }] } },
        });

        expect(resolved.data).toEqual({ accounts: { data: [{ id: 'a' }] } });
        expect(resolved.failures).toEqual([]);
        expect(resolved.truncated).toBe(false);
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
        const definitions = [definition('a')];
        const { result, rerender } = renderHook(
            ({ refreshToken }) =>
                useUserWidgetData('dash-1', definitions, true, {
                    refreshToken,
                }),
            { initialProps: { refreshToken: 0 } },
        );

        await waitFor(() => expect(result.current.status).toBe('ready'));
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
        const definitions = [definition('a')];
        const { result, rerender } = renderHook(
            ({ refreshToken }) =>
                useUserWidgetData('dash-1', definitions, true, {
                    refreshToken,
                }),
            { initialProps: { refreshToken: 0 } },
        );

        await waitFor(() => expect(result.current.status).toBe('ready'));
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
