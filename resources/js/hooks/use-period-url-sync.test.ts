import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({ replace: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { replace: mocks.replace },
}));

vi.mock('@/routes', () => ({
    cashflow: ({ query }: { query: Record<string, string> }) => ({
        url: `/cashflow?period=${query.period}&period_type=${query.period_type}`,
    }),
}));

const { usePeriodUrlSync } = await import('./use-period-url-sync');

describe('usePeriodUrlSync', () => {
    beforeEach(() => {
        mocks.replace.mockClear();
    });

    it('writes the period into the URL without asking the server', () => {
        renderHook(() =>
            usePeriodUrlSync(new Date('2026-08-17'), 'month', null, 'month'),
        );

        expect(mocks.replace).toHaveBeenCalledOnce();

        const [params] = mocks.replace.mock.calls[0];
        expect(params.url).toBe('/cashflow?period=2026-08&period_type=month');
        // Both are load-bearing: the props callback is what makes the guard go false
        // on the next render, and preserveState defaults to false for a client-side
        // visit, which would remount the page and reset the period that drove us here.
        expect(params.preserveState).toBe(true);
        expect(typeof params.props).toBe('function');
    });

    it('leaves the URL alone once it already carries the period', () => {
        renderHook(() =>
            usePeriodUrlSync(
                new Date('2026-08-17'),
                'month',
                '2026-08',
                'month',
            ),
        );

        expect(mocks.replace).not.toHaveBeenCalled();
    });

    it('terminates: the props it hands back satisfy its own guard', () => {
        const { rerender } = renderHook(
            ({ initialPeriod }: { initialPeriod: string | null }) =>
                usePeriodUrlSync(
                    new Date('2026-08-17'),
                    'month',
                    initialPeriod,
                    'month',
                ),
            { initialProps: { initialPeriod: null as string | null } },
        );

        const [params] = mocks.replace.mock.calls[0];
        const nextProps = params.props({ period: null, periodType: 'month' });

        // Feeding its own output back in is what a re-render does. If this still
        // fired, the page would loop forever.
        rerender({ initialPeriod: nextProps.period });

        expect(mocks.replace).toHaveBeenCalledOnce();
    });

    it('formats quarter and year periods', () => {
        renderHook(() =>
            usePeriodUrlSync(
                new Date('2026-08-17'),
                'quarter',
                null,
                'quarter',
            ),
        );
        expect(mocks.replace.mock.calls[0][0].url).toContain('period=2026-Q3');

        mocks.replace.mockClear();
        renderHook(() =>
            usePeriodUrlSync(new Date('2026-08-17'), 'year', null, 'year'),
        );
        expect(mocks.replace.mock.calls[0][0].url).toContain('period=2026&');
    });
});
