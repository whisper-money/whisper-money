import { type UserWidgetDefinition } from '@/types/dashboard';
import { type WidgetRuntimeError } from '@/widgets/runtime/widget-error-boundary';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    resetReportedWidgetErrors,
    useWidgetErrorReporter,
} from './use-widget-error-reporter';

function definition(revision: number): UserWidgetDefinition {
    return {
        id: 'widget-1',
        name: 'Groceries',
        revision,
        js: '',
        manifest: { size: { width: '1/2', height: 'm' } },
        last_error: null,
        last_error_at: null,
        revisions: [],
    };
}

const ERROR: WidgetRuntimeError = {
    phase: 'render',
    message: 'rows is undefined',
    stack: null,
    componentStack: null,
};

describe('useWidgetErrorReporter', () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        resetReportedWidgetErrors();
        fetchMock.mockReset().mockResolvedValue({ ok: true, status: 204 });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    it('stores the error with its widget once per revision', () => {
        const { result } = renderHook(() => useWidgetErrorReporter(true));

        act(() => {
            result.current.report(definition(3), ERROR);
            result.current.report(definition(3), ERROR);
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/widgets/widget-1/errors');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ revision: 3, ...ERROR });

        act(() => result.current.report(definition(4), ERROR));
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('stores it once even when the dashboard mounts again', () => {
        for (let mount = 0; mount < 2; mount++) {
            const { result, unmount } = renderHook(() =>
                useWidgetErrorReporter(true),
            );
            act(() => result.current.report(definition(1), ERROR));
            unmount();
        }

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('sends it again next time when the report did not land', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500 });
        const { result } = renderHook(() => useWidgetErrorReporter(true));

        await act(async () => result.current.report(definition(1), ERROR));
        act(() => result.current.report(definition(1), ERROR));

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('remembers it for the details, even when it cannot store it', () => {
        const { result } = renderHook(() => useWidgetErrorReporter(false));

        act(() => result.current.report(definition(2), ERROR));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(result.current.errors['widget-1']).toMatchObject({
            revision: 2,
            message: 'rows is undefined',
        });
    });
});
