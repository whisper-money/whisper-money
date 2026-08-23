import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useCashflowData } from './use-cashflow-data';

const period = {
    from: new Date('2026-08-01T00:00:00Z'),
    to: new Date('2026-08-31T00:00:00Z'),
    periodType: 'month' as const,
};

function jsonResponse(status: number, body: unknown) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    };
}

const emptyPayload = {
    current: {},
    previous: {},
    data: [],
    income_categories: [],
    expense_categories: [],
    categories: [],
};

describe('useCashflowData', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('reports a server error instead of leaving zeros looking like an answer', async () => {
        // Laravel answers with a JSON body on the way down, which is exactly why
        // this used to sail through as data.
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    jsonResponse(500, { message: 'Server Error' }),
                ),
        );
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const { result } = renderHook(() => useCashflowData(period));

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.hasError).toBe(true);
        // And the figures were never touched, so the page has nothing to show but
        // its placeholders.
        expect(result.current.summary.current.income).toBe(0);
    });

    it('clears the error once a load gets through', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(500, { message: 'nope' }))
            .mockResolvedValue(jsonResponse(200, emptyPayload));
        vi.stubGlobal('fetch', fetchMock);
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const { result } = renderHook(() => useCashflowData(period));

        await waitFor(() => expect(result.current.hasError).toBe(true));

        result.current.refetch();

        await waitFor(() => expect(result.current.hasError).toBe(false));
    });
});
